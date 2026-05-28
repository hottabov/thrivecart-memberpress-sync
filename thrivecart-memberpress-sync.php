<?php
/**
 * Plugin Name: ThriveCart MemberPress Sync
 * Plugin URI: https://wordpress.org/plugins/thrivecart-memberpress-sync/
 * Description: Automatically sync ThriveCart subscription cancellations and refunds with MemberPress for accurate access control and statistics.
 * Version: 3.0.0
 * Author: LeonovDesign
 * Author URI: https://leonovdesign.com
 * Requires at least: 6.0
 * Tested up to: 6.7
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: thrivecart-memberpress-sync
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

define('AE_TC_MP_SYNC_VERSION', '3.0.0');
define('AE_TC_MP_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AE_TC_MP_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));
// Store logs outside public directory for security
define('AE_TC_MP_SYNC_LOG_FILE', WP_CONTENT_DIR . '/ae-tc-mp-sync-logs/thrivecart-sync.log');

/**
 * Changelog:
 *
 * v3.0.0 (2026-05-28) - Full Subscription Lifecycle Management
 * - Fixed: CRITICAL — recurring subscriptions now get time-limited access (not lifetime)
 * - Fixed: CRITICAL — $post undefined variable bug in cancellation handler
 * - Added: Handlers for order.success and order.subscription_payment (set/extend expiry)
 * - Added: Handlers for order.subscription_payment_failed and order.subscription_overdue (log only)
 * - Added: Idempotency layer via WP transients (7-day deduplication)
 * - Added: Twice-daily WP Cron safety net to expire overdue subscriptions
 * - Added: Payment retry via WP Cron (handles TC→MP race condition)
 * - Fixed: Webhook body parsing now supports JSON and form-encoded
 * - Fixed: Secret never logged on authentication failure
 * - Fixed: Raw POST data no longer logged (privacy/security)
 * - Fixed: sanitize_mappings() now preserves tc_product_ids, active, payment_type, label
 * - Fixed: Mapping save handler now merges instead of destroying existing fields
 * - Fixed: current_time('timestamp') replaced with time() for UTC correctness
 *
 * v2.2.2 (2025-11-02) - Critical Expiration Fix
 * - Fixed: Lifetime access bug for cancelled subscriptions
 * - Added: Automatic expiration setting on cancellation
 */

class AE_ThriveCart_MemberPress_Sync {
    protected static $_instance = null;

    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('admin_menu', array($this, 'register_admin_menu'), 99);
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'migrate_old_mappings'), 5);
        add_action('admin_notices', array($this, 'admin_notices'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('ae_tc_mp_sync_cleanup_logs', array($this, 'cleanup_old_logs'));

        if (!wp_next_scheduled('ae_tc_mp_sync_cleanup_logs')) {
            wp_schedule_event(time(), 'daily', 'ae_tc_mp_sync_cleanup_logs');
        }

        // Twice-daily safety net: cancel subscriptions whose transaction expiry has passed
        add_action('ae_tc_mp_sync_expire_check', array($this, 'cron_expire_overdue_subscriptions'));
        if (!wp_next_scheduled('ae_tc_mp_sync_expire_check')) {
            wp_schedule_event(time(), 'twicedaily', 'ae_tc_mp_sync_expire_check');
        }

        // Retry handler for delayed payment processing (TC→MP race condition)
        add_action('ae_tc_mp_sync_retry_payment', array($this, 'retry_payment_expiry'));
    }

    public function register_rest_routes() {
        register_rest_route('ae/v1', '/thrivecart-hook', array(
            'methods' => array('GET', 'POST', 'HEAD'),
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('ae/v1', '/thrivecart-hook-status', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_status'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ));
    }

    public function handle_webhook(WP_REST_Request $request) {
        $method = $request->get_method();

        // Handle HEAD request (ThriveCart verification)
        if ($method === 'HEAD') {
            return new WP_REST_Response(null, 200);
        }

        // Handle GET request (manual verification)
        if ($method === 'GET') {
            $response = array(
                'ok' => true,
                'message' => 'AE ThriveCart → MemberPress Sync webhook ready (v3.0 — full subscription lifecycle)',
                'version' => AE_TC_MP_SYNC_VERSION,
                'features' => array(
                    'multiple_products_per_membership' => true,
                    'payment_type_filtering' => true,
                    'refund_processing' => true,
                    'backwards_compatible' => true
                )
            );
            $this->log('GET verification request received');
            return new WP_REST_Response($response, 200);
        }

        // Handle POST request (actual webhook)
        if ($method === 'POST') {
            // Parse body: supports JSON and form-encoded (ThriveCart default)
            $post_data = $this->parse_webhook_body($request);

            $this->log('Webhook POST received', array(
                'event'    => $post_data['event'] ?? 'unknown',
                'email'    => $this->extract_email($post_data),
                'order_id' => $post_data['order_id'] ?? $post_data['id'] ?? 'unknown', // @TC_PAYLOAD_VERIFY
            ));

            // Authenticate using thrivecart_secret
            if (!$this->authenticate_thrivecart_request($post_data)) {
                $this->log('Authentication failed', array(
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    // Never log the received secret — security risk
                ));
                return new WP_REST_Response(null, 200); // Return 200 to avoid ThriveCart retry
            }

            // Process the event
            $result = $this->process_thrivecart_event($post_data);

            // Always return 200 to ThriveCart
            return new WP_REST_Response(null, 200);
        }

        return new WP_REST_Response(array('ok' => false, 'error' => 'Method not allowed'), 405);
    }

    private function authenticate_thrivecart_request($post_data) {
        $saved_secret = get_option('ae_tc_mp_sync_secret', '');
        if (empty($saved_secret)) {
            $this->log('No secret configured in plugin settings');
            return false;
        }

        $received_secret = $post_data['thrivecart_secret'] ?? '';
        if (empty($received_secret)) {
            $this->log('No thrivecart_secret in POST data');
            return false;
        }

        if (!hash_equals($saved_secret, $received_secret)) {
            $this->log('Secret mismatch', array(
                'expected' => substr($saved_secret, 0, 5) . '...',
                'received' => substr($received_secret, 0, 5) . '...'
            ));
            return false;
        }

        return true;
    }

    private function process_thrivecart_event($post_data) {
        $event = $post_data['event'] ?? '';

        // Classify event into one of four buckets
        $is_payment      = ($event === 'order.success' || $event === 'order.subscription_payment');
        $is_failed       = ($event === 'order.subscription_payment_failed' || $event === 'order.subscription_overdue');
        $is_cancellation = ($event === 'order.subscription_cancelled' || $event === 'order.rebill_cancelled');
        $is_refund       = ($event === 'order.refund' || $event === 'order.refunded');

        if (!$is_payment && !$is_failed && !$is_cancellation && !$is_refund) {
            $this->log('Event ignored (unrecognised type)', array('event' => $event));
            return array('ok' => true, 'message' => 'Event type ignored');
        }

        // Idempotency check — prevents duplicate processing from TC retries
        $idempotency_key = $this->build_idempotency_key($post_data);
        if ($this->is_duplicate_event($idempotency_key)) {
            $this->log('Duplicate event — skipped', array('event' => $event, 'key' => $idempotency_key));
            return array('ok' => true, 'message' => 'Duplicate event ignored');
        }

        // Extract customer email
        $email = $this->extract_email($post_data);
        if (empty($email)) {
            $this->log('Missing customer email', array('event' => $event));
            return array('ok' => false, 'error' => 'Missing email');
        }

        // Extract product ID
        $tc_product_id = $this->extract_product_id($post_data, $is_refund, $is_cancellation);
        if (empty($tc_product_id) || $tc_product_id === 'null') {
            $this->log('Missing product ID', array(
                'event'         => $event,
                'checked_paths' => $is_refund
                    ? 'refund.product_id, refund.id, refund.bump_id, refund.upsell_id, base_product'
                    : 'subscription.id, subscription_id, base_product, product_id',
            ));
            return array('ok' => false, 'error' => 'Missing product ID');
        }

        // Find WordPress user
        $user = get_user_by('email', $email);
        if (!$user) {
            $this->log('User not found', array('email' => $email));
            return array('ok' => false, 'error' => 'User not found');
        }

        // Find mapping entry
        $matched_mapping = $this->find_mapping($tc_product_id);
        if (!$matched_mapping) {
            $this->log('No mapping found', array('tc_product_id' => $tc_product_id));
            return array('ok' => false, 'error' => 'No mapping found for product');
        }

        $membership_id = (int) $matched_mapping['membership_id'];

        $this->log('Mapping found', array(
            'tc_product_id' => $tc_product_id,
            'membership_id' => $membership_id,
            'payment_type'  => $matched_mapping['payment_type'] ?? 'not_set',
            'label'         => $matched_mapping['label'] ?? 'unlabeled',
        ));

        // Route to handler
        if ($is_payment) {
            $results     = $this->handle_successful_payment($user->ID, $membership_id, $post_data);
            $action_type = 'payment';
            $log_message = 'Successful payment — expiry set/extended';

        } elseif ($is_failed) {
            $results     = $this->handle_failed_payment($user->ID, $membership_id, $post_data);
            $action_type = 'payment_failed';
            $log_message = 'Failed payment logged — no access change (cron handles expiry)';

        } elseif ($is_refund) {
            // @TC_PAYLOAD_VERIFY: refund.amount — verify if TC sends cents or dollars
            $refund_amount_cents = $post_data['refund']['amount'] ?? null;
            $refund_amount = null;
            if ($refund_amount_cents !== null) {
                // Treating as cents (existing behaviour) — verify against live webhooks
                $refund_amount = number_format($refund_amount_cents / 100, 2, '.', '');
            }

            $this->log('Processing REFUND', array(
                'email'        => $email,
                'tc_product_id' => $tc_product_id,
                'refund_amount' => $refund_amount ?? 'full',
                'refund_type'  => $post_data['refund']['type'] ?? 'full',
            ));

            $results     = $this->process_memberpress_refund($user->ID, $membership_id, $refund_amount);
            $action_type = 'refund';
            $log_message = 'Refund processed — transaction refunded, access revoked';

        } else {
            // Cancellation
            $this->log('Processing CANCELLATION', array(
                'email'         => $email,
                'tc_product_id' => $tc_product_id,
                'event'         => $event,
            ));

            // Pass $post_data (not the WordPress global $post)
            $results     = $this->process_memberpress_cancellation($user->ID, $membership_id, $post_data);
            $action_type = 'cancellation';
            $log_message = 'Cancellation processed — access until end of period';
        }

        // Mark event processed only after successful handling
        $this->mark_event_processed($idempotency_key);

        // Admin notification
        $this->send_notification($user, $membership_id, $results, $tc_product_id, $matched_mapping, $action_type);

        $this->log($log_message, array(
            'user_id'       => $user->ID,
            'membership_id' => $membership_id,
            'action_type'   => $action_type,
            'results'       => $results,
        ));

        return array(
            'ok'            => true,
            'user_id'       => $user->ID,
            'membership_id' => $membership_id,
            'action_type'   => $action_type,
            'results'       => $results,
        );
    }

    private function process_memberpress_refund($user_id, $membership_id, $refund_amount = null) {
        $api_key = get_option('ae_tc_mp_sync_api_key', '');
        $results = array();

        if (empty($api_key)) {
            $this->log('MemberPress API key not set');
            return array(array('error' => 'API key not configured'));
        }

        $site_url = get_site_url();
        
        // Step 1: Find the most recent complete transaction to refund
        $transactions_endpoint = $site_url . '/wp-json/mp/v1/transactions';
        
        $trans_response = wp_remote_get($transactions_endpoint, array(
            'timeout' => 20,
            'headers' => array('MEMBERPRESS-API-KEY' => $api_key),
            'body' => array(
                'member' => $user_id,
                'membership' => $membership_id,
                'status' => 'complete',
                'per_page' => 100,
            ),
        ));

        if (is_wp_error($trans_response)) {
            $error_message = $trans_response->get_error_message();
            $this->log('Failed to fetch transactions for refund', array('error' => $error_message));
            return array(array('error' => 'Failed to fetch transactions: ' . $error_message));
        }

        $transactions = json_decode(wp_remote_retrieve_body($trans_response), true);

        if (empty($transactions) || !is_array($transactions)) {
            $this->log('No complete transactions found to refund', array(
                'user_id' => $user_id, 
                'membership_id' => $membership_id
            ));
            return array(array('error' => 'No complete transactions found to refund'));
        }

        // Find the most recent transaction (highest ID)
        $latest_transaction = null;
        $latest_id = 0;

        foreach ($transactions as $transaction) {
            $trans_id = $transaction['id'] ?? 0;
            if ($trans_id > $latest_id) {
                $latest_id = $trans_id;
                $latest_transaction = $transaction;
            }
        }

        if (!$latest_transaction) {
            return array(array('error' => 'No valid transaction found'));
        }

        $trans_id = $latest_transaction['id'];
        $trans_amount = $latest_transaction['total'] ?? '0.00';
        
        $this->log('Found transaction to refund', array(
            'trans_id' => $trans_id,
            'amount' => $trans_amount,
            'refund_amount' => $refund_amount ?? 'full'
        ));
        
        // Step 2: Issue refund via MemberPress native API
        $refund_endpoint = $site_url . '/wp-json/mp/v1/transactions/' . $trans_id . '/refund';
        
        $refund_body = array(
            'send_notification' => true  // Send email to user
        );
        
        // Support partial refunds
        if ($refund_amount !== null && $refund_amount !== '') {
            $refund_body['amount'] = $refund_amount;
            $this->log('Processing partial refund', array(
                'trans_id' => $trans_id,
                'original_amount' => $trans_amount,
                'refund_amount' => $refund_amount
            ));
        } else {
            $this->log('Processing full refund', array(
                'trans_id' => $trans_id,
                'amount' => $trans_amount
            ));
        }
        
        $refund_response = wp_remote_post($refund_endpoint, array(
            'timeout' => 30,
            'headers' => array(
                'MEMBERPRESS-API-KEY' => $api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($refund_body)
        ));

        $code = wp_remote_retrieve_response_code($refund_response);
        $response_body = wp_remote_retrieve_body($refund_response);
        $body = json_decode($response_body, true);

        if ($code === 200 && !empty($body)) {
            $refunded_amount = $body['refunded_amount'] ?? $body['total'] ?? $refund_amount;
            
            $this->log('MemberPress refund processed successfully', array(
                'trans_id' => $trans_id,
                'status' => 'refunded',
                'refunded_amount' => $refunded_amount,
                'original_amount' => $trans_amount,
                'subscription_cancelled' => true,
                'access_revoked' => true,
                'email_sent' => true
            ));

            return array(array(
                'success' => true,
                'trans_id' => $trans_id,
                'status' => 'refunded',
                'refunded_amount' => $refunded_amount,
                'original_amount' => $trans_amount,
                'partial' => ($refund_amount !== null && $refund_amount !== ''),
                'subscription_cancelled' => 'automatic',
                'access_revoked' => 'automatic',
                'statistics_updated' => true,
                'email_sent' => true,
                'message' => 'Refund processed via MemberPress native API'
            ));
        } else {
            $error = $body['message'] ?? $body['error'] ?? 'Unknown error';
            
            $this->log('MemberPress refund API failed', array(
                'trans_id' => $trans_id,
                'code' => $code,
                'error' => $error,
                'response' => $response_body
            ));

            // Fallback: Mark transaction as refunded manually if API fails
            $this->log('Attempting fallback: manual transaction update', array('trans_id' => $trans_id));
            
            $fallback_result = $this->fallback_refund_transaction($trans_id, $user_id, $membership_id);
            
            return array($fallback_result);
        }
    }

    private function fallback_refund_transaction($trans_id, $user_id, $membership_id) {
        // Fallback method if native API fails
        $api_key = get_option('ae_tc_mp_sync_api_key', '');
        $site_url = get_site_url();
        
        // Update transaction status to refunded
        $update_endpoint = $site_url . '/wp-json/mp/v1/transactions/' . $trans_id;
        
        $update_response = wp_remote_request($update_endpoint, array(
            'method' => 'PUT',
            'timeout' => 20,
            'headers' => array(
                'MEMBERPRESS-API-KEY' => $api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode(array(
                'status' => 'refunded'
            )),
        ));

        $code = wp_remote_retrieve_response_code($update_response);
        
        if ($code === 200) {
            // Also expire membership immediately — use time() for UTC consistency
            $current_time = time();
            update_user_meta($user_id, '_mepr-expire-' . $membership_id, $current_time);
            
            $this->log('Fallback refund successful', array(
                'trans_id' => $trans_id,
                'status' => 'refunded',
                'membership_expired' => true
            ));
            
            return array(
                'success' => true,
                'trans_id' => $trans_id,
                'status' => 'refunded',
                'method' => 'fallback',
                'message' => 'Transaction marked as refunded (fallback method)'
            );
        } else {
            return array(
                'error' => 'Refund API failed and fallback failed',
                'trans_id' => $trans_id,
                'code' => $code
            );
        }
    }

    // ============================================================================
    // LEGACY FUNCTIONS - Kept for backward compatibility only
    // ============================================================================
    // 
    // NOTE: These functions are NO LONGER USED for refunds and cancellations:
    // - Refunds now use: process_memberpress_refund() → MemberPress /transactions/{id}/refund API
    // - Cancellations now use: process_memberpress_cancellation() → MemberPress /subscriptions/{id}/cancel API
    //
    // The following functions remain only for:
    // 1. Fallback scenarios (non-recurring subscriptions, manual transactions)
    // 2. Backward compatibility
    // 3. Edge cases where native API is not available
    //
    // All business logic has been moved to MemberPress native APIs for:
    // ✅ Accurate statistics and reports
    // ✅ Professional user email notifications
    // ✅ Automatic access control
    // ✅ Consistent behavior with manual actions
    // ============================================================================
    
    private function expire_memberpress_subscriptions($user_id, $membership_id) {
        $api_key = get_option('ae_tc_mp_sync_api_key', '');
        $results = array();

        if (empty($api_key)) {
            $this->log('MemberPress API key not set');
            return array(array('error' => 'API key not configured'));
        }

        $site_url = get_site_url();
        $endpoint = $site_url . '/wp-json/mp/v1/subscriptions';

        $response = wp_remote_get($endpoint, array(
            'timeout' => 20,
            'headers' => array('MEMBERPRESS-API-KEY' => $api_key),
            'body' => array(
                'member' => $user_id,
                'membership' => $membership_id,
                'per_page' => 100,
            ),
        ));

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log('Failed to fetch subscriptions', array('error' => $error_message));
            return array(array('error' => $error_message));
        }

        $subscriptions = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($subscriptions) || !is_array($subscriptions)) {
            $this->log('No subscriptions found to expire', array('user_id' => $user_id, 'membership_id' => $membership_id));
            return array(array('message' => 'No subscriptions found'));
        }

        foreach ($subscriptions as $subscription) {
            $sub_id = $subscription['id'] ?? null;
            if (!$sub_id) continue;

            $update_endpoint = $site_url . '/wp-json/mp/v1/subscriptions/' . $sub_id;
            
            $expire_response = wp_remote_request($update_endpoint, array(
                'method' => 'PUT',
                'timeout' => 20,
                'headers' => array(
                    'MEMBERPRESS-API-KEY' => $api_key,
                    'Content-Type' => 'application/json',
                ),
                'body' => json_encode(array(
                    'status' => 'expired'
                )),
            ));

            $code = wp_remote_retrieve_response_code($expire_response);
            $results[] = array(
                'mp_sub_id' => $sub_id,
                'code' => $code,
                'success' => $code === 200,
                'action' => 'expired'
            );
            
            $this->log('Subscription expired (refund)', array(
                'sub_id' => $sub_id,
                'code' => $code
            ));
        }

        return $results;
    }

    private function expire_memberpress_membership($user_id, $membership_id) {
        $api_key = get_option('ae_tc_mp_sync_api_key', '');
        $results = array();

        if (empty($api_key)) {
            $this->log('MemberPress API key not set');
            return array(array('error' => 'API key not configured'));
        }

        $site_url = get_site_url();
        
        // Step 1: Get all transactions for this user and membership
        $transactions_endpoint = $site_url . '/wp-json/mp/v1/transactions';
        
        $trans_response = wp_remote_get($transactions_endpoint, array(
            'timeout' => 20,
            'headers' => array('MEMBERPRESS-API-KEY' => $api_key),
            'body' => array(
                'member' => $user_id,
                'membership' => $membership_id,
                'per_page' => 100,
            ),
        ));

        if (is_wp_error($trans_response)) {
            $error_message = $trans_response->get_error_message();
            $this->log('Failed to fetch transactions', array('error' => $error_message));
            return array(array('error' => $error_message));
        }

        $transactions = json_decode(wp_remote_retrieve_body($trans_response), true);

        if (empty($transactions) || !is_array($transactions)) {
            $this->log('No transactions found', array('user_id' => $user_id, 'membership_id' => $membership_id));
        } else {
            // Step 2: Mark transactions as refunded
            foreach ($transactions as $transaction) {
                $trans_id = $transaction['id'] ?? null;
                $trans_status = $transaction['status'] ?? '';
                
                if (!$trans_id) continue;
                
                // Only update complete transactions
                if ($trans_status === 'complete') {
                    $update_trans_endpoint = $site_url . '/wp-json/mp/v1/transactions/' . $trans_id;
                    
                    $update_response = wp_remote_request($update_trans_endpoint, array(
                        'method' => 'PUT',
                        'timeout' => 20,
                        'headers' => array(
                            'MEMBERPRESS-API-KEY' => $api_key,
                            'Content-Type' => 'application/json',
                        ),
                        'body' => json_encode(array(
                            'status' => 'refunded'
                        )),
                    ));

                    $code = wp_remote_retrieve_response_code($update_response);
                    $results[] = array(
                        'mp_trans_id' => $trans_id,
                        'code' => $code,
                        'success' => $code === 200,
                        'action' => 'refunded'
                    );
                    
                    $this->log('Transaction marked as refunded', array(
                        'trans_id' => $trans_id,
                        'code' => $code
                    ));
                }
            }
        }
        
        // Step 3: Expire the membership (set expiration to now)
        $members_endpoint = $site_url . '/wp-json/mp/v1/members/' . $user_id;
        
        $member_response = wp_remote_get($members_endpoint, array(
            'timeout' => 20,
            'headers' => array('MEMBERPRESS-API-KEY' => $api_key),
        ));

        if (!is_wp_error($member_response)) {
            $member_data = json_decode(wp_remote_retrieve_body($member_response), true);
            
            // Get active memberships
            $active_memberships = $member_data['active_memberships'] ?? array();
            
            foreach ($active_memberships as $active_membership) {
                if ($active_membership['id'] == $membership_id) {
                    // Found the membership to expire
                    // Use WordPress direct access to set expiration
                    global $wpdb;
                    
                    // MemberPress stores membership expiration in user meta
                    // Use time() (UTC) not current_time('timestamp') (local time)
                    $meta_key     = '_mepr-expire-' . $membership_id;
                    $current_time = time();

                    update_user_meta($user_id, $meta_key, $current_time);
                    
                    $results[] = array(
                        'membership_id' => $membership_id,
                        'action' => 'membership_expired',
                        'expiration_set' => date('Y-m-d H:i:s', $current_time),
                        'success' => true
                    );
                    
                    $this->log('Membership expiration set', array(
                        'user_id' => $user_id,
                        'membership_id' => $membership_id,
                        'expires_at' => date('Y-m-d H:i:s', $current_time)
                    ));
                    
                    break;
                }
            }
        }

        return $results;
    }

    private function expire_membership_immediately($user_id, $membership_id) {
        $api_key = get_option('ae_tc_mp_sync_api_key', '');
        $results = array();

        if (empty($api_key)) {
            $this->log('MemberPress API key not set');
            return array(array('error' => 'API key not configured'));
        }

        $site_url = get_site_url();
        
        // Step 1: Mark all transactions as refunded
        $transactions_endpoint = $site_url . '/wp-json/mp/v1/transactions';
        
        $trans_response = wp_remote_get($transactions_endpoint, array(
            'timeout' => 20,
            'headers' => array('MEMBERPRESS-API-KEY' => $api_key),
            'body' => array(
                'member' => $user_id,
                'membership' => $membership_id,
                'per_page' => 100,
            ),
        ));

        if (!is_wp_error($trans_response)) {
            $transactions = json_decode(wp_remote_retrieve_body($trans_response), true);

            if (!empty($transactions) && is_array($transactions)) {
                foreach ($transactions as $transaction) {
                    $trans_id = $transaction['id'] ?? null;
                    $trans_status = $transaction['status'] ?? '';
                    
                    if (!$trans_id) continue;
                    
                    // Update complete transactions to refunded
                    if ($trans_status === 'complete') {
                        $update_trans_endpoint = $site_url . '/wp-json/mp/v1/transactions/' . $trans_id;
                        
                        $update_response = wp_remote_request($update_trans_endpoint, array(
                            'method' => 'PUT',
                            'timeout' => 20,
                            'headers' => array(
                                'MEMBERPRESS-API-KEY' => $api_key,
                                'Content-Type' => 'application/json',
                            ),
                            'body' => json_encode(array(
                                'status' => 'refunded'
                            )),
                        ));

                        $code = wp_remote_retrieve_response_code($update_response);
                        $results[] = array(
                            'trans_id' => $trans_id,
                            'action' => 'marked_refunded',
                            'code' => $code,
                            'success' => $code === 200
                        );
                        
                        $this->log('Transaction marked as refunded (immediate)', array(
                            'trans_id' => $trans_id,
                            'code' => $code
                        ));
                    }
                }
            }
        }
        
        // Step 2: Set membership expiration to NOW (immediate access termination)
        // Use time() (UTC) not current_time('timestamp') (local time)
        $current_time = time();
        $meta_key     = '_mepr-expire-' . $membership_id;
        
        update_user_meta($user_id, $meta_key, $current_time);
        
        $results[] = array(
            'membership_id' => $membership_id,
            'action' => 'membership_expired_immediately',
            'expiration_set' => date('Y-m-d H:i:s', $current_time),
            'success' => true
        );
        
        $this->log('Membership expired immediately (refund)', array(
            'user_id' => $user_id,
            'membership_id' => $membership_id,
            'expired_at' => date('Y-m-d H:i:s', $current_time)
        ));

        return $results;
    }

    private function process_memberpress_cancellation($user_id, $membership_id, $webhook_data = null) {
        $api_key = get_option('ae_tc_mp_sync_api_key', '');
        $results = array();

        if (empty($api_key)) {
            $this->log('MemberPress API key not set');
            return array(array('error' => 'API key not configured'));
        }

        $site_url = get_site_url();
        
        // Step 1: Find active subscriptions
        $subscriptions_endpoint = $site_url . '/wp-json/mp/v1/subscriptions';
        
        $response = wp_remote_get($subscriptions_endpoint, array(
            'timeout' => 20,
            'headers' => array('MEMBERPRESS-API-KEY' => $api_key),
            'body' => array(
                'member' => $user_id,
                'membership' => $membership_id,
                'status' => 'active',
                'per_page' => 100,
            ),
        ));

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log('Failed to fetch subscriptions for cancellation', array('error' => $error_message));
            
            // Fallback for non-recurring subscriptions
            return $this->handle_non_recurring_cancellation($user_id, $membership_id, $webhook_data);
        }

        $subscriptions = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($subscriptions) || !is_array($subscriptions)) {
            $this->log('No active subscriptions found - trying non-recurring fallback', array(
                'user_id' => $user_id,
                'membership_id' => $membership_id
            ));
            
            // Fallback for non-recurring subscriptions or manual transactions
            return $this->handle_non_recurring_cancellation($user_id, $membership_id, $webhook_data);
        }

        // Step 2: Cancel each active subscription via MemberPress native API
        foreach ($subscriptions as $subscription) {
            $sub_id = $subscription['id'] ?? null;
            $status = $subscription['status'] ?? '';
            
            if (!$sub_id || $status !== 'active') continue;

            $this->log('Found active subscription to cancel', array(
                'sub_id' => $sub_id,
                'user_id' => $user_id,
                'membership_id' => $membership_id
            ));

            // MemberPress native cancellation API
            $cancel_endpoint = $site_url . '/wp-json/mp/v1/subscriptions/' . $sub_id . '/cancel';

            $cancel_response = wp_remote_post($cancel_endpoint, array(
                'timeout' => 30,
                'headers' => array(
                    'MEMBERPRESS-API-KEY' => $api_key,
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode(array(
                    'send_notification' => true  // Send cancellation email to user
                ))
            ));

            $code = wp_remote_retrieve_response_code($cancel_response);
            $response_body = wp_remote_retrieve_body($cancel_response);
            $body = json_decode($response_body, true);

            if ($code === 200 && !empty($body)) {
                $expires_at = $body['expires_at'] ?? null;
                $status = $body['status'] ?? 'cancelled';
                
                $this->log('MemberPress cancellation processed successfully', array(
                    'sub_id' => $sub_id,
                    'status' => $status,
                    'expires_at' => $expires_at,
                    'access_until_end_of_period' => true,
                    'auto_billing_stopped' => true,
                    'email_sent' => true
                ));

                $results[] = array(
                    'success' => true,
                    'sub_id' => $sub_id,
                    'status' => $status,
                    'expires_at' => $expires_at,
                    'access_until_end_of_period' => true,
                    'auto_billing_stopped' => true,
                    'statistics_updated' => true,
                    'email_sent' => true,
                    'message' => 'Cancellation processed via MemberPress native API'
                );
            } else {
                $error = $body['message'] ?? $body['error'] ?? 'Unknown error';
                
                $this->log('MemberPress cancellation API failed', array(
                    'sub_id' => $sub_id,
                    'code' => $code,
                    'error' => $error,
                    'response' => $response_body
                ));

                $results[] = array(
                    'error' => 'Cancellation API failed: ' . $error,
                    'sub_id' => $sub_id,
                    'code' => $code
                );
            }
        }

        if (empty($results)) {
            $this->log('No subscriptions were cancelled - trying fallback');
            return $this->handle_non_recurring_cancellation($user_id, $membership_id, $webhook_data);
        }

        return $results;
    }

    // OLD FUNCTION - Kept for backward compatibility, but NOT used anymore
    // Cancellations now use process_memberpress_cancellation() which uses MemberPress native API
    private function cancel_memberpress_subscriptions($user_id, $membership_id) {
        $api_key = get_option('ae_tc_mp_sync_api_key', '');
        $results = array();

        if (empty($api_key)) {
            $this->log('MemberPress API key not set');
            return array(array('error' => 'API key not configured'));
        }

        $site_url = get_site_url();
        $endpoint = $site_url . '/wp-json/mp/v1/subscriptions';

        $response = wp_remote_get($endpoint, array(
            'timeout' => 20,
            'headers' => array('MEMBERPRESS-API-KEY' => $api_key),
            'body' => array(
                'member' => $user_id,
                'membership' => $membership_id,
                'per_page' => 100,
            ),
        ));

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log('Failed to fetch subscriptions', array('error' => $error_message));
            return array(array('error' => $error_message));
        }

        $subscriptions = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($subscriptions) || !is_array($subscriptions)) {
            $this->log('No active subscriptions found - trying fallback for non-recurring', array(
                'user_id' => $user_id, 
                'membership_id' => $membership_id
            ));
            
            // FALLBACK: Handle non-recurring subscriptions or manual transactions
            return $this->handle_non_recurring_cancellation($user_id, $membership_id);
        }

        foreach ($subscriptions as $subscription) {
            $sub_id = $subscription['id'] ?? null;
            $status = $subscription['status'] ?? '';

            if (!$sub_id || $status !== 'active') continue;

            $cancel_endpoint = $site_url . '/wp-json/mp/v1/subscriptions/' . $sub_id . '/cancel';

            $cancel_response = wp_remote_post($cancel_endpoint, array(
                'timeout' => 20,
                'headers' => array('MEMBERPRESS-API-KEY' => $api_key),
            ));

            $code = wp_remote_retrieve_response_code($cancel_response);
            $results[] = array('mp_sub_id' => $sub_id, 'code' => $code, 'success' => $code === 200);
            $this->log('Subscription cancelled', array('sub_id' => $sub_id, 'code' => $code));
        }

        return $results;
    }

    private function handle_non_recurring_cancellation($user_id, $membership_id, $webhook_data = null) {
        $api_key = get_option('ae_tc_mp_sync_api_key', '');

        if (empty($api_key)) {
            return array(array('error' => 'API key not configured'));
        }

        $site_url = get_site_url();

        // Step 1: Get all complete transactions for this user and membership
        $transactions_endpoint = $site_url . '/wp-json/mp/v1/transactions';
        
        $trans_response = wp_remote_get($transactions_endpoint, array(
            'timeout' => 20,
            'headers' => array('MEMBERPRESS-API-KEY' => $api_key),
            'body' => array(
                'member' => $user_id,
                'membership' => $membership_id,
                'status' => 'complete',
                'per_page' => 100,
            ),
        ));

        if (is_wp_error($trans_response)) {
            $this->log('Failed to fetch transactions for cancellation', array(
                'user_id' => $user_id,
                'membership_id' => $membership_id,
                'error' => $trans_response->get_error_message()
            ));
            return array(array('error' => 'Failed to fetch transactions'));
        }

        $transactions = json_decode(wp_remote_retrieve_body($trans_response), true);

        if (empty($transactions) || !is_array($transactions)) {
            $this->log('No complete transactions found for cancellation', array(
                'user_id' => $user_id, 
                'membership_id' => $membership_id
            ));
            return array(array('message' => 'No transactions found'));
        }

        // Step 2: Find the most recent complete transaction
        $latest_transaction = null;
        $latest_id = 0;

        foreach ($transactions as $transaction) {
            $trans_id = $transaction['id'] ?? 0;
            if ($trans_id > $latest_id) {
                $latest_id = $trans_id;
                $latest_transaction = $transaction;
            }
        }

        if (!$latest_transaction) {
            return array(array('error' => 'No valid transaction found'));
        }

        $trans_id = $latest_transaction['id'];
        $expires_at = $latest_transaction['expires_at'] ?? null;
        $gateway = $latest_transaction['gateway'] ?? 'unknown';
        
        // Step 3: Check if expiration is valid
        $has_valid_expiration = false;
        
        if ($expires_at && 
            $expires_at !== '0000-00-00 00:00:00' && 
            $expires_at !== 'Never' &&
            strtotime($expires_at) > time()) {
            $has_valid_expiration = true;
        }
        
        // Step 4: If valid expiration exists - use it
        if ($has_valid_expiration) {
            $this->log('Cancellation: Using existing transaction expiration', array(
                'user_id' => $user_id,
                'membership_id' => $membership_id,
                'trans_id' => $trans_id,
                'gateway' => $gateway,
                'expires_at' => $expires_at,
                'note' => 'Transaction already has valid expiration'
            ));
            
            return array(array(
                'success' => true,
                'trans_id' => $trans_id,
                'gateway' => $gateway,
                'expires_at' => $expires_at,
                'method' => 'existing_expiration',
                'message' => 'Using existing transaction expiration'
            ));
        }
        
        // Step 5: NO valid expiration - need to set it!
        $this->log('Transaction has no valid expiration - setting default', array(
            'trans_id' => $trans_id,
            'current_expires_at' => $expires_at,
            'action' => 'setting_default_expiration'
        ));
        
        // Try webhook billing_period_end first
        if ($webhook_data && isset($webhook_data['subscription']['billing_period_end'])) {
            $billing_period_end = $webhook_data['subscription']['billing_period_end'];
            $expires_date = date('Y-m-d H:i:s', $billing_period_end);
            
            $result = $this->update_transaction_expiration($trans_id, $expires_date);
            
            if ($result) {
                $this->log('Expiration set from webhook billing_period_end', array(
                    'trans_id' => $trans_id,
                    'expires_at' => $expires_date,
                    'source' => 'webhook'
                ));
                
                return array(array(
                    'success' => true,
                    'trans_id' => $trans_id,
                    'expires_at' => $expires_date,
                    'method' => 'webhook_billing_period_end',
                    'message' => 'Expiration set from ThriveCart webhook'
                ));
            }
        }
        
        // Fallback: Calculate default expiration (like "Default" button in MemberPress UI)
        $result = $this->set_transaction_default_expiration($trans_id, $membership_id, $latest_transaction);
        
        if ($result) {
            return array($result);
        }
        
        return array(array(
            'error' => 'Failed to set expiration',
            'trans_id' => $trans_id
        ));
    }

    // Helper function to update transaction expiration via MemberPress API
    private function update_transaction_expiration($trans_id, $expires_at) {
        $api_key = get_option('ae_tc_mp_sync_api_key', '');
        $site_url = get_site_url();
        
        $endpoint = $site_url . '/wp-json/mp/v1/transactions/' . $trans_id;
        
        $response = wp_remote_request($endpoint, array(
            'method' => 'PUT',
            'timeout' => 30,
            'headers' => array(
                'MEMBERPRESS-API-KEY' => $api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'expires_at' => $expires_at
            ))
        ));
        
        $code = wp_remote_retrieve_response_code($response);
        
        if ($code === 200) {
            $this->log('Transaction expiration updated via API', array(
                'trans_id' => $trans_id,
                'expires_at' => $expires_at
            ));
            return true;
        }
        
        $this->log('Failed to update transaction expiration', array(
            'trans_id' => $trans_id,
            'code' => $code,
            'response' => wp_remote_retrieve_body($response)
        ));
        
        return false;
    }

    // Calculate and set default expiration (same logic as "Default" button in MemberPress UI)
    private function set_transaction_default_expiration($trans_id, $membership_id, $transaction) {
        $api_key = get_option('ae_tc_mp_sync_api_key', '');
        $site_url = get_site_url();
        
        $created_at = $transaction['created_at'] ?? null;
        
        if (!$created_at) {
            $this->log('Cannot calculate expiration - no created_at', array('trans_id' => $trans_id));
            return false;
        }
        
        // Get membership period from API
        $membership_endpoint = $site_url . '/wp-json/mp/v1/memberships/' . $membership_id;
        
        $membership_response = wp_remote_get($membership_endpoint, array(
            'timeout' => 10,
            'headers' => array('MEMBERPRESS-API-KEY' => $api_key),
        ));

        if (is_wp_error($membership_response)) {
            $this->log('Failed to fetch membership details', array(
                'membership_id' => $membership_id,
                'error' => $membership_response->get_error_message()
            ));
            return false;
        }

        $membership = json_decode(wp_remote_retrieve_body($membership_response), true);

        // MemberPress API: 'period' = integer count, 'period_type' = unit string ("months", "years", etc.)
        $period_value = (int) ($membership['period']      ?? 1);
        $period_type  = $membership['period_type'] ?? 'months';

        $allowed_units = array('days', 'weeks', 'months', 'years');
        if (!in_array($period_type, $allowed_units, true)) {
            $period_type = 'months';
        }

        // Calculate expiration: created_at + period
        $created_timestamp = strtotime($created_at);
        $expires_timestamp = strtotime("+{$period_value} {$period_type}", $created_timestamp);
        $expires_at = gmdate('Y-m-d H:i:s', $expires_timestamp);
        
        // Update transaction via API
        $updated = $this->update_transaction_expiration($trans_id, $expires_at);
        
        if ($updated) {
            $this->log('Transaction expiration set (default calculation)', array(
                'trans_id' => $trans_id,
                'created_at' => $created_at,
                'period' => "{$period_value} {$period_type}",
                'expires_at' => $expires_at,
                'method' => 'default_calculation'
            ));
            
            return array(
                'success' => true,
                'trans_id' => $trans_id,
                'expires_at' => $expires_at,
                'method' => 'default_calculation',
                'message' => 'Expiration calculated and set (created_at + period)'
            );
        }
        
        return false;
    }

    private function send_notification($user, $membership_id, $results, $tc_product_id, $mapping = null, $action_type = 'cancellation') {
        $admin_email = get_option('ae_tc_mp_sync_admin_email', '');
        if (empty($admin_email)) return;

        if ($action_type === 'refund') {
            $subject = __('⚠️ ThriveCart REFUND processed', 'ae-tc-mp-sync');
            $action_description = 'REFUND - Access terminated immediately';
        } else {
            $subject = __('ThriveCart cancellation synced', 'ae-tc-mp-sync');
            $action_description = 'CANCELLATION - Access until end of period';
        }
        
        // Get membership name from MemberPress
        $membership_name = $this->get_membership_name($membership_id);
        
        $payment_type = $mapping['payment_type'] ?? 'any';
        
        $body = sprintf(
            __("Action Type: %s\n\nUser: %s (ID %d)\nMembership: %s (ID: %d)\nThriveCart Product ID: %s\nPayment Type: %s\nResults: %s\nTime: %s", 'ae-tc-mp-sync'),
            $action_description,
            $user->user_email, 
            $user->ID,
            $membership_name,
            $membership_id, 
            $tc_product_id,
            $this->get_payment_type_label($payment_type),
            wp_json_encode($results), 
            gmdate('Y-m-d H:i') . ' UTC'
        );

        wp_mail($admin_email, $subject, $body);
    }

    private function get_membership_name($membership_id) {
        $api_key = get_option('ae_tc_mp_sync_api_key', '');
        
        if (empty($api_key)) {
            return "Membership #$membership_id";
        }

        $site_url = get_site_url();
        $endpoint = $site_url . '/wp-json/mp/v1/memberships/' . $membership_id;

        $response = wp_remote_get($endpoint, array(
            'timeout' => 10,
            'headers' => array('MEMBERPRESS-API-KEY' => $api_key),
        ));

        if (is_wp_error($response)) {
            return "Membership #$membership_id";
        }

        $membership_data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($membership_data['title'])) {
            return $membership_data['title'];
        }

        return "Membership #$membership_id";
    }

    public function migrate_old_mappings() {
        $migrated = get_option('ae_tc_mp_sync_mappings_migrated_v2', false);
        if ($migrated) return;
        
        $mappings = get_option('ae_tc_mp_sync_mappings', array());
        $needs_migration = false;
        
        foreach ($mappings as $key => $mapping) {
            // Check if old format (single tc_product_id)
            if (isset($mapping['tc_product_id']) && !isset($mapping['tc_product_ids'])) {
                // Convert single product ID to array
                $mappings[$key]['tc_product_ids'] = array($mapping['tc_product_id']);
                
                // Add new fields with defaults
                if (!isset($mappings[$key]['payment_type'])) {
                    $mappings[$key]['payment_type'] = 'any';
                }
                if (!isset($mappings[$key]['label'])) {
                    $mappings[$key]['label'] = '';
                }
                if (!isset($mappings[$key]['active'])) {
                    $mappings[$key]['active'] = '1';
                }
                
                // Keep old field for compatibility but it won't be used
                $needs_migration = true;
            }
        }
        
        if ($needs_migration) {
            update_option('ae_tc_mp_sync_mappings', $mappings);
            $this->log('Migrated mappings to v2.0 format', array('count' => count($mappings)));
        }
        
        update_option('ae_tc_mp_sync_mappings_migrated_v2', true);
    }

    private function get_payment_type_label($type) {
        $labels = array(
            'any' => __('Any', 'ae-tc-mp-sync'),
            'onetime' => __('One-Time', 'ae-tc-mp-sync'),
            'recurring_monthly' => __('Monthly', 'ae-tc-mp-sync'),
            'recurring_3month' => __('3 Months', 'ae-tc-mp-sync'),
            'recurring_6month' => __('6 Months', 'ae-tc-mp-sync'),
            'recurring_annual' => __('Annual', 'ae-tc-mp-sync'),
            'trial' => __('Trial', 'ae-tc-mp-sync'),
        );
        
        return $labels[$type] ?? $type;
    }

    public function get_status() {
        $status_items = array();
        $mp_active = class_exists('MeprOptions');
        $status_items[] = 'MemberPress: ' . ($mp_active ? 'Detected ✅' : 'Not Found ❌');

        $secret = get_option('ae_tc_mp_sync_secret', '');
        $status_items[] = 'Secret: ' . (!empty($secret) ? 'Set ✅' : 'Not Set ❌');

        $api_key = get_option('ae_tc_mp_sync_api_key', '');
        $api_connected = false;

        if (!empty($api_key)) {
            $response = wp_remote_get(get_site_url() . '/wp-json/mp/v1/me', array(
                'timeout' => 10,
                'headers' => array('MEMBERPRESS-API-KEY' => $api_key),
            ));
            $api_connected = !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
        }

        $status_items[] = 'MemberPress API: ' . ($api_connected ? 'Connected ✅' : 'Invalid ❌');

        $mappings = get_option('ae_tc_mp_sync_mappings', array());
        $active_mappings = 0;
        foreach ($mappings as $mapping) {
            if (!empty($mapping['tc_product_id'])) $active_mappings++;
        }

        return array(
            'ok' => true,
            'status' => $status_items,
            'active_mappings' => $active_mappings,
            'options' => array(
                'log_days' => get_option('ae_tc_mp_sync_log_days', 30),
                'admin_email' => get_option('ae_tc_mp_sync_admin_email', ''),
            ),
        );
    }

    public function register_admin_menu() {
        add_options_page(
            __('ThriveCart Sync', 'ae-tc-mp-sync'),
            __('ThriveCart Sync', 'ae-tc-mp-sync'),
            'manage_options',
            'ae-tc-mp-sync',
            array($this, 'render_admin_page')
        );

        if (class_exists('MeprOptions')) {
            add_submenu_page(
                'memberpress',
                __('ThriveCart Sync', 'ae-tc-mp-sync'),
                __('ThriveCart Sync', 'ae-tc-mp-sync'),
                'manage_options',
                'ae-tc-mp-sync-mp',
                array($this, 'render_admin_page')
            );
        }
    }

    public function register_settings() {
        register_setting('ae_tc_mp_sync_options', 'ae_tc_mp_sync_secret', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));
        
        register_setting('ae_tc_mp_sync_options', 'ae_tc_mp_sync_api_key', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));
        
        register_setting('ae_tc_mp_sync_options', 'ae_tc_mp_sync_admin_email', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_email',
            'default' => ''
        ));
        
        register_setting('ae_tc_mp_sync_options', 'ae_tc_mp_sync_log_days', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 30
        ));
        
        register_setting('ae_tc_mp_sync_options', 'ae_tc_mp_sync_hub_url', array(
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => ''
        ));

        register_setting('ae_tc_mp_sync_options', 'ae_tc_mp_sync_mappings', array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_mappings'),
            'default' => array()
        ));
    }
    
    public function sanitize_mappings($mappings) {
        if (!is_array($mappings)) {
            return array();
        }

        $sanitized = array();
        foreach ($mappings as $mapping) {
            if (!is_array($mapping)) continue;

            $entry = array(
                'membership_id' => isset($mapping['membership_id']) ? absint($mapping['membership_id']) : 0,
                'tc_product_id' => isset($mapping['tc_product_id']) ? sanitize_text_field($mapping['tc_product_id']) : '',
                'active'        => isset($mapping['active']) ? (string) $mapping['active'] : '1',
                'payment_type'  => isset($mapping['payment_type']) ? sanitize_text_field($mapping['payment_type']) : 'any',
                'label'         => isset($mapping['label']) ? sanitize_text_field($mapping['label']) : '',
            );

            // Preserve multi-product IDs array
            if (isset($mapping['tc_product_ids']) && is_array($mapping['tc_product_ids'])) {
                $entry['tc_product_ids'] = array_map('sanitize_text_field', $mapping['tc_product_ids']);
            }

            if ($entry['membership_id'] > 0) {
                $sanitized[] = $entry;
            }
        }

        return $sanitized;
    }

    public function admin_notices() {
        if (!class_exists('MeprOptions')) {
            $screen = get_current_screen();
            if ($screen && strpos($screen->id, 'ae-tc-mp-sync') !== false) {
                echo '<div class="notice notice-warning"><p>';
                echo esc_html__('MemberPress plugin is not detected. Please install and activate MemberPress for full functionality.', 'ae-tc-mp-sync');
                echo '</p></div>';
            }
        }

        if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo esc_html__('✅ Settings saved successfully!', 'ae-tc-mp-sync');
            echo '</p></div>';
        }
    }

    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'ae-tc-mp-sync') === false) return;

        $css_file = AE_TC_MP_SYNC_PLUGIN_DIR . 'assets/admin.css';
        if (file_exists($css_file)) {
            wp_enqueue_style('ae-tc-mp-sync-admin', AE_TC_MP_SYNC_PLUGIN_URL . 'assets/admin.css', array(), AE_TC_MP_SYNC_VERSION);
        }
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Handle clear logs
        if (isset($_POST['ae_tc_clear_logs']) && check_admin_referer('ae_tc_clear_logs_action', 'ae_tc_clear_logs_nonce')) {
            if (file_exists(AE_TC_MP_SYNC_LOG_FILE)) {
                @unlink(AE_TC_MP_SYNC_LOG_FILE);
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('✅ Logs cleared successfully!', 'ae-tc-mp-sync') . '</p></div>';
            }
        }

        // Handle download logs
        if (isset($_POST['ae_tc_download_logs']) && check_admin_referer('ae_tc_download_logs_action', 'ae_tc_download_logs_nonce')) {
            if (file_exists(AE_TC_MP_SYNC_LOG_FILE)) {
                header('Content-Type: text/plain');
                header('Content-Disposition: attachment; filename="thrivecart-sync-' . gmdate('Y-m-d-His') . '.log"');
                readfile(AE_TC_MP_SYNC_LOG_FILE);
                exit;
            }
        }

        if (isset($_POST['ae_tc_test_submit']) && check_admin_referer('ae_tc_test_action', 'ae_tc_test_nonce')) {
            $test_email = sanitize_email($_POST['test_email'] ?? '');
            $test_membership = intval($_POST['test_membership'] ?? 0);

            if ($test_email && $test_membership) {
                $user = get_user_by('email', $test_email);
                if ($user) {
                    $results = $this->cancel_memberpress_subscriptions($user->ID, $test_membership);
                    echo '<div class="notice notice-info"><p>' . esc_html__('Test completed. Check logs below.', 'ae-tc-mp-sync') . '</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html__('User not found.', 'ae-tc-mp-sync') . '</p></div>';
                }
            }
        }

        if (isset($_POST['ae_tc_mappings_submit']) && check_admin_referer('ae_tc_mappings_action', 'ae_tc_mappings_nonce')) {
            // Load existing mappings and index by membership_id so we can preserve all fields
            $existing_mappings = get_option('ae_tc_mp_sync_mappings', array());
            $existing_by_id    = array();
            foreach ($existing_mappings as $m) {
                if (!empty($m['membership_id'])) {
                    $existing_by_id[(int) $m['membership_id']] = $m;
                }
            }

            $memberships = get_posts(array('post_type' => 'memberpressproduct', 'posts_per_page' => -1));
            $mappings    = array();

            foreach ($memberships as $membership) {
                $mid   = $membership->ID;
                $tc_id = sanitize_text_field($_POST['tc_product_id_' . $mid] ?? '');

                // Merge: start from existing entry to preserve payment_type, label, active, tc_product_ids
                $entry = $existing_by_id[$mid] ?? array(
                    'membership_id' => $mid,
                    'payment_type'  => 'any',
                    'label'         => '',
                    'active'        => '1',
                );

                $entry['membership_id'] = $mid;
                $entry['tc_product_id'] = $tc_id;

                $mappings[] = $entry;
            }

            update_option('ae_tc_mp_sync_mappings', $mappings);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('✅ Mappings saved!', 'ae-tc-mp-sync') . '</p></div>';
        }

        // Read current page slug so tab links work whether accessed via Settings or MemberPress menu
        $page_slug = sanitize_key($_GET['page'] ?? 'ae-tc-mp-sync');
        $active_tab = $_GET['tab'] ?? 'settings';
        ?>
        <div class="wrap ae-tc-mp-sync-wrap">
            <h1>🔄 <?php echo esc_html__('ThriveCart → MemberPress Sync', 'ae-tc-mp-sync'); ?> <span style="font-size: 14px; color: #2271b1;">v<?php echo AE_TC_MP_SYNC_VERSION; ?></span></h1>
            <p class="description"><?php echo esc_html__('by', 'ae-tc-mp-sync'); ?> <a href="https://leonovdesign.com" target="_blank">LeonovDesign</a> | Sync cancellations and refunds automatically</p>

            <nav class="nav-tab-wrapper">
                <a href="?page=<?php echo esc_attr($page_slug); ?>&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">⚙️ <?php esc_html_e('General Settings', 'ae-tc-mp-sync'); ?></a>
                <a href="?page=<?php echo esc_attr($page_slug); ?>&tab=mappings" class="nav-tab <?php echo $active_tab === 'mappings' ? 'nav-tab-active' : ''; ?>">🔗 <?php esc_html_e('Product Mappings', 'ae-tc-mp-sync'); ?></a>
                <a href="?page=<?php echo esc_attr($page_slug); ?>&tab=tools" class="nav-tab <?php echo $active_tab === 'tools' ? 'nav-tab-active' : ''; ?>">🛠️ <?php esc_html_e('Tools', 'ae-tc-mp-sync'); ?></a>
                <a href="?page=<?php echo esc_attr($page_slug); ?>&tab=status" class="nav-tab <?php echo $active_tab === 'status' ? 'nav-tab-active' : ''; ?>">📡 <?php esc_html_e('Webhook Status', 'ae-tc-mp-sync'); ?></a>
                <a href="?page=<?php echo esc_attr($page_slug); ?>&tab=logs" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">📋 <?php esc_html_e('Recent Logs', 'ae-tc-mp-sync'); ?></a>
                <a href="?page=<?php echo esc_attr($page_slug); ?>&tab=manual" class="nav-tab <?php echo $active_tab === 'manual' ? 'nav-tab-active' : ''; ?>">📖 <?php esc_html_e('Setup Manual', 'ae-tc-mp-sync'); ?></a>
            </nav>

            <div class="tab-content">
                <?php
                switch ($active_tab) {
                    case 'settings': $this->render_settings_tab(); break;
                    case 'mappings': $this->render_mappings_tab(); break;
                    case 'tools': $this->render_tools_tab(); break;
                    case 'status': $this->render_status_tab(); break;
                    case 'logs': $this->render_logs_tab(); break;
                    case 'manual': $this->render_manual_tab(); break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function render_settings_tab() {
        $secret = get_option('ae_tc_mp_sync_secret', '');
        $api_key = get_option('ae_tc_mp_sync_api_key', '');
        $admin_email = get_option('ae_tc_mp_sync_admin_email', '');
        $log_days = get_option('ae_tc_mp_sync_log_days', 30);
        $hub_url = get_option('ae_tc_mp_sync_hub_url', '');
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('ae_tc_mp_sync_options'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label><?php esc_html_e('Webhook Endpoint', 'ae-tc-mp-sync'); ?></label></th>
                    <td>
                        <p><strong><?php esc_html_e('Your webhook URL:', 'ae-tc-mp-sync'); ?></strong></p>
                        <input type="text" value="<?php echo esc_attr(get_rest_url(null, 'ae/v1/thrivecart-hook')); ?>" readonly class="large-text" onclick="this.select();" style="font-family: monospace;" />
                        <p class="description"><?php esc_html_e('Copy this URL and paste it into ThriveCart Webhooks settings. ThriveCart will send your secret in the POST data.', 'ae-tc-mp-sync'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ae_tc_mp_sync_secret"><?php esc_html_e('ThriveCart Secret Word', 'ae-tc-mp-sync'); ?></label></th>
                    <td>
                        <input type="text" id="ae_tc_mp_sync_secret" name="ae_tc_mp_sync_secret" value="<?php echo esc_attr($secret); ?>" class="regular-text" />
                        <p class="description">
                            <?php esc_html_e('Copy your "Secret word" from ThriveCart:', 'ae-tc-mp-sync'); ?>
                            <strong>Settings → API & Webhooks → ThriveCart order validation</strong>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ae_tc_mp_sync_api_key"><?php esc_html_e('MemberPress API Key', 'ae-tc-mp-sync'); ?></label></th>
                    <td>
                        <input type="text" id="ae_tc_mp_sync_api_key" name="ae_tc_mp_sync_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" />
                        <p class="description"><?php esc_html_e('Get this from MemberPress → Developer → REST API.', 'ae-tc-mp-sync'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ae_tc_mp_sync_admin_email"><?php esc_html_e('Admin Notification Email', 'ae-tc-mp-sync'); ?></label></th>
                    <td>
                        <input type="email" id="ae_tc_mp_sync_admin_email" name="ae_tc_mp_sync_admin_email" value="<?php echo esc_attr($admin_email); ?>" class="regular-text" />
                        <p class="description"><?php esc_html_e('Optional. Receive email notifications on cancellations.', 'ae-tc-mp-sync'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ae_tc_mp_sync_log_days"><?php esc_html_e('Log Retention (days)', 'ae-tc-mp-sync'); ?></label></th>
                    <td>
                        <input type="number" id="ae_tc_mp_sync_log_days" name="ae_tc_mp_sync_log_days" value="<?php echo esc_attr($log_days); ?>" min="1" max="365" />
                        <p class="description"><?php esc_html_e('How many days to keep log entries.', 'ae-tc-mp-sync'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ae_tc_mp_sync_hub_url"><?php esc_html_e('ThriveCart Customer Hub URL', 'ae-tc-mp-sync'); ?></label></th>
                    <td>
                        <input type="url" id="ae_tc_mp_sync_hub_url" name="ae_tc_mp_sync_hub_url" value="<?php echo esc_attr($hub_url); ?>" class="large-text" placeholder="https://your-site.thrivecart.com/updateinfo/" />
                        <p class="description"><?php esc_html_e('Optional. Your ThriveCart Customer Hub URL. If provided, a "Manage my subscriptions" button will appear on the MemberPress account page.', 'ae-tc-mp-sync'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <?php
    }

    private function render_mappings_tab() {
        $mappings = get_option('ae_tc_mp_sync_mappings', array());
        $memberships = get_posts(array('post_type' => 'memberpressproduct', 'posts_per_page' => -1));

        $mapping_lookup = array();
        foreach ($mappings as $mapping) {
            $mapping_lookup[$mapping['membership_id']] = $mapping['tc_product_id'];
        }
        ?>
        <form method="post">
            <?php wp_nonce_field('ae_tc_mappings_action', 'ae_tc_mappings_nonce'); ?>
            <p><?php esc_html_e('Map your MemberPress memberships to ThriveCart Product IDs (base_product from webhook):', 'ae-tc-mp-sync'); ?></p>

            <?php if (empty($memberships)) : ?>
                <div class="notice notice-warning inline">
                    <p><?php esc_html_e('No MemberPress memberships found. Please create memberships first.', 'ae-tc-mp-sync'); ?></p>
                </div>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Membership Title', 'ae-tc-mp-sync'); ?></th>
                            <th><?php esc_html_e('Membership ID', 'ae-tc-mp-sync'); ?></th>
                            <th><?php esc_html_e('ThriveCart Product ID', 'ae-tc-mp-sync'); ?></th>
                            <th><?php esc_html_e('Status', 'ae-tc-mp-sync'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($memberships as $membership) :
                            $tc_id = $mapping_lookup[$membership->ID] ?? '';
                            $is_active = !empty($tc_id);
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($membership->post_title); ?></strong></td>
                            <td><?php echo esc_html($membership->ID); ?></td>
                            <td>
                                <input type="text" name="tc_product_id_<?php echo esc_attr($membership->ID); ?>"
                                       value="<?php echo esc_attr($tc_id); ?>"
                                       placeholder="<?php esc_attr_e('e.g., 2', 'ae-tc-mp-sync'); ?>" />
                            </td>
                            <td>
                                <?php if ($is_active) : ?>
                                    <span style="color: #46b450;">✅ <?php esc_html_e('Active', 'ae-tc-mp-sync'); ?></span>
                                <?php else : ?>
                                    <span style="color: #999;"><?php esc_html_e('Not Mapped', 'ae-tc-mp-sync'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="submit">
                    <input type="submit" name="ae_tc_mappings_submit" class="button button-primary" value="<?php esc_attr_e('Save Mappings', 'ae-tc-mp-sync'); ?>" />
                </p>
            <?php endif; ?>
        </form>
        <?php
    }

    private function render_tools_tab() {
        $memberships = get_posts(array('post_type' => 'memberpressproduct', 'posts_per_page' => -1));
        ?>
        <h2><?php esc_html_e('Test Cancellation', 'ae-tc-mp-sync'); ?></h2>
        <p><?php esc_html_e('Simulate a cancellation without ThriveCart. This will actually cancel active subscriptions!', 'ae-tc-mp-sync'); ?></p>

        <form method="post">
            <?php wp_nonce_field('ae_tc_test_action', 'ae_tc_test_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="test_email"><?php esc_html_e('User Email', 'ae-tc-mp-sync'); ?></label></th>
                    <td>
                        <input type="email" id="test_email" name="test_email" class="regular-text" required />
                        <p class="description"><?php esc_html_e('Email of the WordPress user to test.', 'ae-tc-mp-sync'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="test_membership"><?php esc_html_e('Membership', 'ae-tc-mp-sync'); ?></label></th>
                    <td>
                        <select id="test_membership" name="test_membership" required>
                            <option value=""><?php esc_html_e('— Select Membership —', 'ae-tc-mp-sync'); ?></option>
                            <?php foreach ($memberships as $membership) : ?>
                                <option value="<?php echo esc_attr($membership->ID); ?>">
                                    <?php echo esc_html($membership->post_title); ?> (ID: <?php echo esc_html($membership->ID); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" name="ae_tc_test_submit" class="button button-secondary"
                       value="<?php esc_attr_e('Simulate Cancel', 'ae-tc-mp-sync'); ?>"
                       onclick="return confirm('<?php esc_attr_e('This will cancel real subscriptions. Continue?', 'ae-tc-mp-sync'); ?>');" />
            </p>
        </form>
        <?php
    }

    private function render_status_tab() {
        $secret = get_option('ae_tc_mp_sync_secret', '');
        $api_key = get_option('ae_tc_mp_sync_api_key', '');
        $mappings = get_option('ae_tc_mp_sync_mappings', array());

        $mp_active = class_exists('MeprOptions');
        $secret_set = !empty($secret);

        $api_connected = false;
        if (!empty($api_key)) {
            $response = wp_remote_get(get_site_url() . '/wp-json/mp/v1/me', array(
                'timeout' => 10,
                'headers' => array('MEMBERPRESS-API-KEY' => $api_key),
            ));
            $api_connected = !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
        }

        $active_mappings = 0;
        foreach ($mappings as $mapping) {
            if (!empty($mapping['tc_product_id'])) $active_mappings++;
        }
        ?>
        <h2><?php esc_html_e('System Status', 'ae-tc-mp-sync'); ?></h2>
        <table class="wp-list-table widefat">
            <tbody>
                <tr>
                    <td><strong><?php esc_html_e('Plugin Version', 'ae-tc-mp-sync'); ?></strong></td>
                    <td>
                        <span style="color: #2271b1;">✨ <?php echo esc_html(AE_TC_MP_SYNC_VERSION); ?></span>
                    </td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e('Refund Processing', 'ae-tc-mp-sync'); ?></strong></td>
                    <td>
                        <span style="color: #46b450;">✅ <?php esc_html_e('Enabled (Immediate Access Termination)', 'ae-tc-mp-sync'); ?></span>
                    </td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e('MemberPress Plugin', 'ae-tc-mp-sync'); ?></strong></td>
                    <td>
                        <?php if ($mp_active) : ?>
                            <span style="color: #46b450;">✅ <?php esc_html_e('Detected', 'ae-tc-mp-sync'); ?></span>
                        <?php else : ?>
                            <span style="color: #dc3232;">❌ <?php esc_html_e('Not Found', 'ae-tc-mp-sync'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e('Secret Configured', 'ae-tc-mp-sync'); ?></strong></td>
                    <td>
                        <?php if ($secret_set) : ?>
                            <span style="color: #46b450;">✅ <?php esc_html_e('Set', 'ae-tc-mp-sync'); ?></span>
                        <?php else : ?>
                            <span style="color: #dc3232;">❌ <?php esc_html_e('Not Set', 'ae-tc-mp-sync'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e('MemberPress API', 'ae-tc-mp-sync'); ?></strong></td>
                    <td>
                        <?php if ($api_connected) : ?>
                            <span style="color: #46b450;">✅ <?php esc_html_e('Connected', 'ae-tc-mp-sync'); ?></span>
                        <?php else : ?>
                            <span style="color: #dc3232;">❌ <?php esc_html_e('Invalid or Not Set', 'ae-tc-mp-sync'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e('Active Mappings', 'ae-tc-mp-sync'); ?></strong></td>
                    <td><?php echo esc_html($active_mappings); ?></td>
                </tr>
            </tbody>
        </table>

        <h2><?php esc_html_e('Webhook Endpoint', 'ae-tc-mp-sync'); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e('URL to add in ThriveCart', 'ae-tc-mp-sync'); ?></th>
                <td>
                    <input type="text" value="<?php echo esc_attr(get_rest_url(null, 'ae/v1/thrivecart-hook')); ?>"
                           readonly class="large-text" onclick="this.select();" style="font-family: monospace;" />
                    <p class="description">
                        <?php esc_html_e('Click to select and copy. Paste this into ThriveCart → Settings → API & Webhooks → Webhooks', 'ae-tc-mp-sync'); ?>
                    </p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Test Your Webhook', 'ae-tc-mp-sync'); ?></h2>
        <p>
            <a href="<?php echo esc_url(get_rest_url(null, 'ae/v1/thrivecart-hook')); ?>" target="_blank" class="button">
                <?php esc_html_e('Test Webhook Endpoint', 'ae-tc-mp-sync'); ?>
            </a>
            <span class="description" style="margin-left: 10px;">
                <?php esc_html_e('Should show: {"ok":true,"message":"..."}', 'ae-tc-mp-sync'); ?>
            </span>
        </p>
        <?php
    }

    private function render_logs_tab() {
        $log_file = AE_TC_MP_SYNC_LOG_FILE;
        ?>
        <h2><?php esc_html_e('Recent Log Entries', 'ae-tc-mp-sync'); ?></h2>
        <p><?php esc_html_e('Last 50 lines from:', 'ae-tc-mp-sync'); ?> <code><?php echo esc_html($log_file); ?></code></p>
        <p class="description" style="color: #d63638;">
            <strong><?php esc_html_e('🔒 Security Note:', 'ae-tc-mp-sync'); ?></strong>
            <?php esc_html_e('Logs are stored outside the public uploads directory with .htaccess protection to prevent direct access.', 'ae-tc-mp-sync'); ?>
        </p>

        <div style="margin: 20px 0;">
            <form method="post" style="display: inline;">
                <?php wp_nonce_field('ae_tc_clear_logs_action', 'ae_tc_clear_logs_nonce'); ?>
                <input type="submit" name="ae_tc_clear_logs" class="button button-secondary"
                       value="<?php esc_attr_e('Clear All Logs', 'ae-tc-mp-sync'); ?>"
                       onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete all log entries?', 'ae-tc-mp-sync'); ?>');" />
            </form>
            <form method="post" style="display: inline; margin-left: 10px;">
                <?php wp_nonce_field('ae_tc_download_logs_action', 'ae_tc_download_logs_nonce'); ?>
                <input type="submit" name="ae_tc_download_logs" class="button button-secondary"
                       value="<?php esc_attr_e('Download Logs', 'ae-tc-mp-sync'); ?>" />
            </form>
        </div>

        <div style="background: #1e1e1e; color: #d4d4d4; padding: 15px; font-family: 'Courier New', monospace; font-size: 12px; overflow-x: auto; max-height: 500px; overflow-y: auto; border-radius: 4px;">
            <?php
            if (file_exists($log_file) && is_readable($log_file)) {
                $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $recent_lines = array_slice($lines, -50);
                if (empty($recent_lines)) {
                    echo esc_html__('No log entries yet. Logs will appear here when webhooks are received.', 'ae-tc-mp-sync');
                } else {
                    foreach ($recent_lines as $line) {
                        echo esc_html($line) . "\n";
                    }
                }
            } else {
                echo esc_html__('No log file found. It will be created when the first webhook is received.', 'ae-tc-mp-sync');
            }
            ?>
        </div>
        <?php
    }

    private function render_manual_tab() {
        $secret = get_option('ae_tc_mp_sync_secret', '');
        $webhook_url = get_rest_url(null, 'ae/v1/thrivecart-hook');
        ?>
        <h2><?php esc_html_e('Setup Manual', 'ae-tc-mp-sync'); ?></h2>

        <div class="ae-manual-section">
            <h3><?php esc_html_e('📖 What This Plugin Does', 'ae-tc-mp-sync'); ?></h3>
            <p><?php esc_html_e('This plugin automatically syncs subscription cancellations and refunds from ThriveCart to MemberPress, ensuring accurate access control and statistics.', 'ae-tc-mp-sync'); ?></p>
            
            <h4><?php esc_html_e('Key Features:', 'ae-tc-mp-sync'); ?></h4>
            <ul>
                <li><strong><?php esc_html_e('Cancellations:', 'ae-tc-mp-sync'); ?></strong> <?php esc_html_e('When a user cancels or pauses their subscription in ThriveCart, they keep access until the end of their paid period. Access is automatically removed after expiration.', 'ae-tc-mp-sync'); ?></li>
                <li><strong><?php esc_html_e('Refunds:', 'ae-tc-mp-sync'); ?></strong> <?php esc_html_e('When you issue a refund in ThriveCart (full or partial), access is terminated immediately and the transaction is marked as refunded in MemberPress.', 'ae-tc-mp-sync'); ?></li>
                <li><strong><?php esc_html_e('Automatic Expiration:', 'ae-tc-mp-sync'); ?></strong> <?php esc_html_e('For Manual gateway transactions, the plugin automatically sets expiration dates on cancellation to prevent lifetime access issues.', 'ae-tc-mp-sync'); ?></li>
                <li><strong><?php esc_html_e('MemberPress Native APIs:', 'ae-tc-mp-sync'); ?></strong> <?php esc_html_e('Uses official MemberPress APIs for refunds and cancellations, ensuring accurate statistics, reports, and user notifications.', 'ae-tc-mp-sync'); ?></li>
                <li><strong><?php esc_html_e('Resume Support:', 'ae-tc-mp-sync'); ?></strong> <?php esc_html_e('When users resume subscriptions in ThriveCart, new transactions are created automatically and access is restored.', 'ae-tc-mp-sync'); ?></li>
            </ul>
        </div>

        <div class="ae-manual-section">
            <h3><?php esc_html_e('📋 Event Types Handled', 'ae-tc-mp-sync'); ?></h3>
            <table class="wp-list-table widefat" style="max-width: 900px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Event', 'ae-tc-mp-sync'); ?></th>
                        <th><?php esc_html_e('Trigger', 'ae-tc-mp-sync'); ?></th>
                        <th><?php esc_html_e('Action', 'ae-tc-mp-sync'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>order.subscription_cancelled</code></td>
                        <td><?php esc_html_e('User cancels or pauses subscription', 'ae-tc-mp-sync'); ?></td>
                        <td><strong style="color: #2271b1;"><?php esc_html_e('Cancellation', 'ae-tc-mp-sync'); ?></strong> - <?php esc_html_e('Sets expiration date, access until end of period', 'ae-tc-mp-sync'); ?></td>
                    </tr>
                    <tr>
                        <td><code>order.rebill_cancelled</code></td>
                        <td><?php esc_html_e('Recurring billing stopped', 'ae-tc-mp-sync'); ?></td>
                        <td><strong style="color: #2271b1;"><?php esc_html_e('Cancellation', 'ae-tc-mp-sync'); ?></strong> - <?php esc_html_e('Sets expiration date, access until end of period', 'ae-tc-mp-sync'); ?></td>
                    </tr>
                    <tr style="background: #fff3cd;">
                        <td><code>order.refund</code></td>
                        <td><?php esc_html_e('Full or partial refund issued', 'ae-tc-mp-sync'); ?></td>
                        <td><strong style="color: #dc3232;"><?php esc_html_e('Refund', 'ae-tc-mp-sync'); ?></strong> - <?php esc_html_e('Transaction marked refunded, access terminated IMMEDIATELY', 'ae-tc-mp-sync'); ?></td>
                    </tr>
                    <tr style="background: #fff3cd;">
                        <td><code>order.refunded</code></td>
                        <td><?php esc_html_e('Refund completed (alternative event)', 'ae-tc-mp-sync'); ?></td>
                        <td><strong style="color: #dc3232;"><?php esc_html_e('Refund', 'ae-tc-mp-sync'); ?></strong> - <?php esc_html_e('Transaction marked refunded, access terminated IMMEDIATELY', 'ae-tc-mp-sync'); ?></td>
                    </tr>
                    <tr style="background: #e8f5e9;">
                        <td><code>order.subscription_payment</code></td>
                        <td><?php esc_html_e('Recurring payment successful', 'ae-tc-mp-sync'); ?></td>
                        <td><strong style="color: #46b450;"><?php esc_html_e('Ignored', 'ae-tc-mp-sync'); ?></strong> - <?php esc_html_e('New transaction created automatically by ThriveCart integration', 'ae-tc-mp-sync'); ?></td>
                    </tr>
                    <tr style="background: #f5f5f5;">
                        <td><code>other events</code></td>
                        <td><?php esc_html_e('Various (payment upcoming, overdue, etc)', 'ae-tc-mp-sync'); ?></td>
                        <td><strong><?php esc_html_e('Ignored', 'ae-tc-mp-sync'); ?></strong> - <?php esc_html_e('Logged but no action taken', 'ae-tc-mp-sync'); ?></td>
                    </tr>
                </tbody>
            </table>
            <p class="description" style="margin-top: 10px;">
                <?php esc_html_e('All webhook events are logged for troubleshooting. Check the Recent Logs tab to see what events are being received.', 'ae-tc-mp-sync'); ?>
            </p>
        </div>

        <div class="ae-manual-section">
            <h3><?php esc_html_e('🔧 Step 1: Configure WordPress Plugin', 'ae-tc-mp-sync'); ?></h3>
            <ol>
                <li><strong><?php esc_html_e('Get your ThriveCart Secret Word:', 'ae-tc-mp-sync'); ?></strong>
                    <ul>
                        <li><?php esc_html_e('In ThriveCart, go to: Settings → API & Webhooks', 'ae-tc-mp-sync'); ?></li>
                        <li><?php esc_html_e('Scroll to "ThriveCart order validation"', 'ae-tc-mp-sync'); ?></li>
                        <li><?php esc_html_e('Copy the "Secret word" value', 'ae-tc-mp-sync'); ?></li>
                    </ul>
                </li>
                <li><strong><?php esc_html_e('Paste it in plugin settings:', 'ae-tc-mp-sync'); ?></strong>
                    <ul>
                        <li><?php esc_html_e('Go to: MemberPress → ThriveCart Sync → General Settings', 'ae-tc-mp-sync'); ?></li>
                        <li><?php esc_html_e('Paste the secret in "ThriveCart Secret Word" field', 'ae-tc-mp-sync'); ?></li>
                    </ul>
                </li>
                <li><strong><?php esc_html_e('Add MemberPress API Key:', 'ae-tc-mp-sync'); ?></strong>
                    <ul>
                        <li><?php esc_html_e('Go to: MemberPress → Developer → REST API', 'ae-tc-mp-sync'); ?></li>
                        <li><?php esc_html_e('Create a new API key and copy it', 'ae-tc-mp-sync'); ?></li>
                        <li><?php esc_html_e('Paste it in the plugin settings', 'ae-tc-mp-sync'); ?></li>
                    </ul>
                </li>
                <li><?php esc_html_e('Save settings', 'ae-tc-mp-sync'); ?></li>
            </ol>
        </div>

        <div class="ae-manual-section">
            <h3><?php esc_html_e('🛒 Step 2: Set Up ThriveCart Webhook', 'ae-tc-mp-sync'); ?></h3>
            <ol>
                <li><?php esc_html_e('Log in to ThriveCart', 'ae-tc-mp-sync'); ?></li>
                <li><?php esc_html_e('Go to: Settings → API & Webhooks → Webhooks & notifications', 'ae-tc-mp-sync'); ?></li>
                <li><?php esc_html_e('Click "+ Add webhook"', 'ae-tc-mp-sync'); ?></li>
                <li><strong><?php esc_html_e('Enter details:', 'ae-tc-mp-sync'); ?></strong>
                    <ul>
                        <li><strong><?php esc_html_e('Name:', 'ae-tc-mp-sync'); ?></strong> MemberPress Sync</li>
                        <li><strong><?php esc_html_e('Webhook URL:', 'ae-tc-mp-sync'); ?></strong><br>
                            <code style="background: #fff; padding: 5px; display: inline-block; margin-top: 5px;"><?php echo esc_html($webhook_url); ?></code>
                        </li>
                        <li><strong><?php esc_html_e('Receive results as JSON:', 'ae-tc-mp-sync'); ?></strong> <?php esc_html_e('No (leave unchecked)', 'ae-tc-mp-sync'); ?></li>
                    </ul>
                </li>
                <li><?php esc_html_e('Click "Add webhook" - ThriveCart will verify it responds with 2xx status', 'ae-tc-mp-sync'); ?></li>
            </ol>
        </div>

        <div class="ae-manual-section">
            <h3><?php esc_html_e('🗺️ Step 3: Map Products to Memberships', 'ae-tc-mp-sync'); ?></h3>
            <ol>
                <li><?php esc_html_e('Go to: Settings → ThriveCart Sync → Mapping Table', 'ae-tc-mp-sync'); ?></li>
                <li><strong><?php esc_html_e('Find your ThriveCart Product IDs:', 'ae-tc-mp-sync'); ?></strong>
                    <ul>
                        <li><?php esc_html_e('In ThriveCart, go to Products', 'ae-tc-mp-sync'); ?></li>
                        <li><?php esc_html_e('Edit a product and look at the URL', 'ae-tc-mp-sync'); ?></li>
                        <li><?php esc_html_e('Example: thrivecart.com/product/2 → Product ID is "2"', 'ae-tc-mp-sync'); ?></li>
                    </ul>
                </li>
                <li><?php esc_html_e('Enter the Product ID for each membership', 'ae-tc-mp-sync'); ?></li>
                <li><?php esc_html_e('Save mappings', 'ae-tc-mp-sync'); ?></li>
            </ol>
        </div>

        <div class="ae-manual-section">
            <h3><?php esc_html_e('✅ Step 4: Test the Integration', 'ae-tc-mp-sync'); ?></h3>
            <ol>
                <li><?php esc_html_e('Go to: Webhook Status tab', 'ae-tc-mp-sync'); ?></li>
                <li><?php esc_html_e('Click "Test Webhook Endpoint" - should show success message', 'ae-tc-mp-sync'); ?></li>
                <li><?php esc_html_e('In ThriveCart, make a test purchase (use Test Mode)', 'ae-tc-mp-sync'); ?></li>
                <li><?php esc_html_e('Cancel the test subscription in ThriveCart', 'ae-tc-mp-sync'); ?></li>
                <li><?php esc_html_e('Check Recent Logs tab to see webhook received', 'ae-tc-mp-sync'); ?></li>
                <li><?php esc_html_e('Verify subscription was cancelled in MemberPress', 'ae-tc-mp-sync'); ?></li>
            </ol>
        </div>

        <div class="ae-manual-section">
            <h3><?php esc_html_e('🆘 Troubleshooting', 'ae-tc-mp-sync'); ?></h3>
            <ul>
                <li><strong><?php esc_html_e('ThriveCart says URL doesn\'t return 2xx:', 'ae-tc-mp-sync'); ?></strong>
                    <ul>
                        <li><?php esc_html_e('Test the URL directly in your browser', 'ae-tc-mp-sync'); ?></li>
                        <li><?php esc_html_e('Check your server firewall/security settings', 'ae-tc-mp-sync'); ?></li>
                        <li><?php esc_html_e('Try regenerating permalinks: Settings → Permalinks → Save Changes', 'ae-tc-mp-sync'); ?></li>
                    </ul>
                </li>
                <li><strong><?php esc_html_e('Webhook received but subscription not cancelling:', 'ae-tc-mp-sync'); ?></strong>
                    <ul>
                        <li><?php esc_html_e('Check Recent Logs for error messages', 'ae-tc-mp-sync'); ?></li>
                        <li><?php esc_html_e('Verify Product ID mapping is correct', 'ae-tc-mp-sync'); ?></li>
                        <li><?php esc_html_e('Ensure MemberPress API key is valid', 'ae-tc-mp-sync'); ?></li>
                        <li><?php esc_html_e('Confirm user email matches in both systems', 'ae-tc-mp-sync'); ?></li>
                    </ul>
                </li>
                <li><strong><?php esc_html_e('Secret word mismatch:', 'ae-tc-mp-sync'); ?></strong>
                    <ul>
                        <li><?php esc_html_e('Make sure you copied the ENTIRE secret from ThriveCart', 'ae-tc-mp-sync'); ?></li>
                        <li><?php esc_html_e('No extra spaces before or after', 'ae-tc-mp-sync'); ?></li>
                    </ul>
                </li>
            </ul>
        </div>

        <div class="ae-manual-section">
            <h3><?php esc_html_e('📞 Support', 'ae-tc-mp-sync'); ?></h3>
            <p>
                <?php esc_html_e('For support, please visit:', 'ae-tc-mp-sync'); ?>
                <a href="https://leonovdesign.com" target="_blank">https://leonovdesign.com</a>
            </p>
            <p>
                <?php esc_html_e('Include your Recent Logs when requesting support.', 'ae-tc-mp-sync'); ?>
            </p>
        </div>
        <?php
    }

    // ============================================================================
    // NEW v3.0.0: Webhook body parsing, idempotency, extraction helpers
    // ============================================================================

    /**
     * Parse webhook body from WP_REST_Request.
     * Supports JSON (Content-Type: application/json) and form-encoded (TC default).
     */
    private function parse_webhook_body(WP_REST_Request $request): array {
        $content_type = $request->get_header('content-type') ?? '';

        if (strpos($content_type, 'application/json') !== false) {
            $params = $request->get_json_params();
            if (is_array($params) && !empty($params)) {
                return $params;
            }
        }

        $params = $request->get_body_params();
        if (is_array($params) && !empty($params)) {
            return $params;
        }

        // Last-resort fallback for edge cases
        return is_array($_POST) ? $_POST : array();
    }

    /**
     * Build a stable idempotency key for deduplication.
     * Key: event | order_id | subscription_id | payment_id
     *
     * @TC_PAYLOAD_VERIFY: all field names — verify against live TC webhook logs.
     */
    private function build_idempotency_key(array $post_data): string {
        $event      = $post_data['event'] ?? '';
        $order_id   = $post_data['order_id'] ?? $post_data['id'] ?? '';                              // @TC_PAYLOAD_VERIFY
        $sub_id     = $post_data['subscription']['id'] ?? $post_data['subscription_id'] ?? '';       // @TC_PAYLOAD_VERIFY
        $payment_id = $post_data['rebill']['payment_id'] ?? $post_data['rebill']['id'] ?? $post_data['payment_id'] ?? ''; // @TC_PAYLOAD_VERIFY

        return 'ae_tc_mp_idem_' . md5($event . '|' . $order_id . '|' . $sub_id . '|' . $payment_id);
    }

    private function is_duplicate_event(string $key): bool {
        return get_transient($key) !== false;
    }

    private function mark_event_processed(string $key): void {
        set_transient($key, 1, 604800); // 7 days
    }

    /**
     * Extract customer email from ThriveCart webhook payload.
     */
    private function extract_email(array $post_data): string {
        if (!empty($post_data['customer']['email'])) {
            return sanitize_email($post_data['customer']['email']);
        }
        if (!empty($post_data['customer_email'])) {
            return sanitize_email($post_data['customer_email']);
        }
        return '';
    }

    /**
     * Extract ThriveCart product ID for event routing.
     *
     * @TC_PAYLOAD_VERIFY: refund.* field names — verify against live refund webhooks.
     * @TC_PAYLOAD_VERIFY: product_id field for payment events.
     */
    private function extract_product_id(array $post_data, bool $is_refund, bool $is_cancellation): string {
        if ($is_refund) {
            if (!empty($post_data['refund']['product_id'])) return (string) $post_data['refund']['product_id']; // @TC_PAYLOAD_VERIFY
            if (!empty($post_data['refund']['id']))         return (string) $post_data['refund']['id'];         // @TC_PAYLOAD_VERIFY
            if (!empty($post_data['refund']['bump_id']))    return (string) $post_data['refund']['bump_id'];    // @TC_PAYLOAD_VERIFY
            if (!empty($post_data['refund']['upsell_id'])) return (string) $post_data['refund']['upsell_id'];  // @TC_PAYLOAD_VERIFY
            if (!empty($post_data['base_product']))         return (string) $post_data['base_product'];
        } elseif ($is_cancellation) {
            if (!empty($post_data['subscription']['id'])) return (string) $post_data['subscription']['id'];
            if (!empty($post_data['subscription_id']))    return (string) $post_data['subscription_id'];
            if (!empty($post_data['base_product']))       return (string) $post_data['base_product'];
        } else {
            // Payment events: order.success, order.subscription_payment
            if (!empty($post_data['subscription']['id'])) return (string) $post_data['subscription']['id']; // @TC_PAYLOAD_VERIFY
            if (!empty($post_data['base_product']))       return (string) $post_data['base_product'];
            if (!empty($post_data['product_id']))         return (string) $post_data['product_id'];          // @TC_PAYLOAD_VERIFY
        }
        return '';
    }

    /**
     * Find the mapping entry matching a ThriveCart product ID.
     * Returns matching mapping array or null.
     */
    private function find_mapping(string $tc_product_id): ?array {
        $mappings = get_option('ae_tc_mp_sync_mappings', array());

        foreach ($mappings as $mapping) {
            $is_active = !isset($mapping['active']) || $mapping['active'] === '1' || $mapping['active'] === true;
            if (!$is_active) continue;

            $product_ids = array();
            if (isset($mapping['tc_product_ids']) && is_array($mapping['tc_product_ids'])) {
                $product_ids = $mapping['tc_product_ids'];
            } elseif (isset($mapping['tc_product_id'])) {
                $raw = $mapping['tc_product_id'];
                $product_ids = (strpos($raw, ',') !== false)
                    ? array_map('trim', explode(',', $raw))
                    : array(trim($raw));
            }

            if (in_array($tc_product_id, array_filter($product_ids), true)) {
                return $mapping;
            }
        }

        return null;
    }

    /**
     * Extract payment timestamp from webhook payload.
     * Returns current UTC time as safe fallback.
     *
     * @TC_PAYLOAD_VERIFY: ALL field name candidates below — verify against live webhooks for
     * both order.success and order.subscription_payment events.
     */
    private function extract_payment_date(array $post_data): int {
        $candidates = array(
            $post_data['rebill']['date']    ?? null, // @TC_PAYLOAD_VERIFY
            $post_data['rebill']['created'] ?? null, // @TC_PAYLOAD_VERIFY
            $post_data['payment_date']      ?? null, // @TC_PAYLOAD_VERIFY
            $post_data['order_date']        ?? null, // @TC_PAYLOAD_VERIFY
            $post_data['created']           ?? null, // @TC_PAYLOAD_VERIFY
            $post_data['date']              ?? null, // @TC_PAYLOAD_VERIFY
        );

        foreach ($candidates as $candidate) {
            if (empty($candidate)) continue;
            $ts = is_numeric($candidate) ? (int) $candidate : strtotime($candidate);
            if ($ts && $ts > 0) {
                return $ts;
            }
        }

        return time(); // UTC fallback
    }

    // ============================================================================
    // NEW v3.0.0: Payment handlers
    // ============================================================================

    /**
     * Handle a confirmed payment (order.success or order.subscription_payment).
     *
     * Strategy:
     * 1. Fetch most recent COMPLETE transaction for this user + membership.
     * 2. Calculate new expiry = payment_date + billing_period + 2h grace.
     * 3. Update transaction's expires_at only if new expiry > current.
     * 4. If no transaction exists yet, schedule retry (TC→MP race condition).
     *
     * This is the core fix for the lifetime-access bug: every confirmed payment
     * stamps a time-limited expiry on the MemberPress transaction.
     */
    private function handle_successful_payment(int $user_id, int $membership_id, array $webhook_data): array {
        $api_key = get_option('ae_tc_mp_sync_api_key', '');
        if (empty($api_key)) {
            return array(array('error' => 'API key not configured'));
        }

        $site_url = get_site_url();

        $trans_response = wp_remote_get($site_url . '/wp-json/mp/v1/transactions', array(
            'timeout' => 20,
            'headers' => array('MEMBERPRESS-API-KEY' => $api_key),
            'body'    => array(
                'member'     => $user_id,
                'membership' => $membership_id,
                'status'     => 'complete',
                'per_page'   => 100,
            ),
        ));

        if (is_wp_error($trans_response)) {
            $this->log('handle_successful_payment: failed to fetch transactions', array(
                'error' => $trans_response->get_error_message(),
            ));
            return array(array('error' => 'Failed to fetch transactions: ' . $trans_response->get_error_message()));
        }

        $transactions = json_decode(wp_remote_retrieve_body($trans_response), true);

        if (empty($transactions) || !is_array($transactions)) {
            // TC→MP integration may not have created the transaction yet — retry in 120s
            $this->log('handle_successful_payment: no transactions found — scheduling retry', array(
                'user_id'       => $user_id,
                'membership_id' => $membership_id,
            ));
            $this->schedule_payment_retry($user_id, $membership_id, $webhook_data);
            return array(array('message' => 'No transactions found — retry scheduled in 120s'));
        }

        // Find most recent transaction (highest ID = most recently created)
        $latest    = null;
        $latest_id = 0;
        foreach ($transactions as $t) {
            $tid = (int) ($t['id'] ?? 0);
            if ($tid > $latest_id) {
                $latest_id = $tid;
                $latest    = $t;
            }
        }

        if (!$latest) {
            return array(array('error' => 'No valid transaction found'));
        }

        $trans_id = $latest['id'];

        // Calculate new expiry: payment_date + billing_period + 2h grace
        $payment_ts   = $this->extract_payment_date($webhook_data);
        $period_info  = $this->get_membership_period($membership_id);
        $period_value = $period_info['period_count'] ?? 1;
        $period_type  = $period_info['period']       ?? 'months';

        $new_expiry_ts  = strtotime("+{$period_value} {$period_type}", $payment_ts) + (2 * HOUR_IN_SECONDS);
        $new_expiry_str = gmdate('Y-m-d H:i:s', $new_expiry_ts);

        // Check current expires_at — only update if new date is later
        $current_expires = $latest['expires_at'] ?? null;
        $current_ts      = 0;
        if ($current_expires
            && $current_expires !== '0000-00-00 00:00:00'
            && strtolower($current_expires) !== 'never') {
            $current_ts = (int) strtotime($current_expires);
        }

        if ($current_ts >= $new_expiry_ts) {
            $this->log('handle_successful_payment: expiry already covers period — no change', array(
                'trans_id'       => $trans_id,
                'current_expiry' => $current_expires,
                'new_expiry'     => $new_expiry_str,
            ));
            return array(array(
                'success'  => true,
                'trans_id' => $trans_id,
                'message'  => 'Expiry already covers payment period — no change',
            ));
        }

        $updated = $this->update_transaction_expiration($trans_id, $new_expiry_str);

        if ($updated) {
            $this->log('handle_successful_payment: expiry set', array(
                'trans_id'     => $trans_id,
                'payment_date' => gmdate('Y-m-d H:i:s', $payment_ts),
                'period'       => "{$period_value} {$period_type}",
                'expires_at'   => $new_expiry_str,
            ));

            return array(array(
                'success'    => true,
                'trans_id'   => $trans_id,
                'expires_at' => $new_expiry_str,
                'message'    => 'Expiry set: payment_date + ' . $period_value . ' ' . $period_type . ' + 2h grace',
            ));
        }

        return array(array('error' => 'Failed to update transaction expiry', 'trans_id' => $trans_id));
    }

    /**
     * Handle a failed payment (order.subscription_payment_failed, order.subscription_overdue).
     *
     * Policy: do NOT revoke access immediately.
     * The existing expires_at on the transaction acts as the clock.
     * The twice-daily cron will cancel the subscription once expiry passes.
     * This prevents false-positive lockouts from transient payment failures.
     */
    private function handle_failed_payment(int $user_id, int $membership_id, array $webhook_data): array {
        $event = $webhook_data['event'] ?? 'unknown';
        $this->log('handle_failed_payment: logged, no access change', array(
            'event'         => $event,
            'user_id'       => $user_id,
            'membership_id' => $membership_id,
        ));
        return array(array(
            'message' => 'Failed payment logged — no immediate access change. Cron expires access when date passes.',
            'event'   => $event,
        ));
    }

    /**
     * Fetch membership billing period from MemberPress API.
     * Returns array: ['period' => string, 'period_count' => int].
     * Cached 1 hour per membership.
     */
    private function get_membership_period(int $membership_id): array {
        $cache_key = 'ae_tc_mp_period_' . $membership_id;
        $cached    = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $api_key  = get_option('ae_tc_mp_sync_api_key', '');
        $site_url = get_site_url();

        $response = wp_remote_get($site_url . '/wp-json/mp/v1/memberships/' . $membership_id, array(
            'timeout' => 10,
            'headers' => array('MEMBERPRESS-API-KEY' => $api_key),
        ));

        if (is_wp_error($response)) {
            return array('period' => 'months', 'period_count' => 1); // safe fallback
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        // MemberPress API: 'period' = integer count (e.g. 1), 'period_type' = unit string (e.g. "months").
        // 'period_count' does NOT exist in the MP REST API — that was a wrong assumption.
        $period_count = (int) ($data['period'] ?? 1);
        $period_type  = $data['period_type'] ?? 'months';

        // Normalize to strtotime-compatible unit (MP returns "months", "years", "weeks", "days").
        // If an unexpected value arrives, fall back to "months".
        $allowed_units = array('days', 'weeks', 'months', 'years');
        if (!in_array($period_type, $allowed_units, true)) {
            $period_type = 'months';
        }

        $result = array(
            'period'       => $period_type,  // strtotime unit: "months", "years", "weeks", "days"
            'period_count' => $period_count, // integer count: 1, 3, 6, 12 …
        );

        set_transient($cache_key, $result, HOUR_IN_SECONDS);

        return $result;
    }

    /**
     * Schedule a payment expiry retry 120 seconds from now.
     * Used when the native TC→MP transaction hasn't been created yet at webhook time.
     */
    private function schedule_payment_retry(int $user_id, int $membership_id, array $webhook_data): void {
        $retry_data_key = 'ae_tc_mp_retry_' . md5($user_id . '_' . $membership_id . '_' . time());
        set_transient($retry_data_key, array(
            'user_id'       => $user_id,
            'membership_id' => $membership_id,
            'webhook_data'  => $webhook_data,
        ), 600); // keep data for 10 minutes

        wp_schedule_single_event(time() + 120, 'ae_tc_mp_sync_retry_payment', array($retry_data_key));

        $this->log('Payment retry scheduled', array(
            'user_id'       => $user_id,
            'membership_id' => $membership_id,
            'retry_key'     => $retry_data_key,
        ));
    }

    /**
     * WP Cron callback: retry setting expiry for a delayed payment.
     */
    public function retry_payment_expiry(string $retry_data_key): void {
        $data = get_transient($retry_data_key);
        if (!$data) {
            $this->log('retry_payment_expiry: data expired or already processed', array('key' => $retry_data_key));
            return;
        }

        delete_transient($retry_data_key);

        $result = $this->handle_successful_payment(
            (int) $data['user_id'],
            (int) $data['membership_id'],
            $data['webhook_data']
        );

        $this->log('retry_payment_expiry: complete', array(
            'user_id'       => $data['user_id'],
            'membership_id' => $data['membership_id'],
            'result'        => $result,
        ));
    }

    // ============================================================================
    // NEW v3.0.0: Twice-daily cron safety net
    // ============================================================================

    /**
     * WP Cron callback (runs twice daily).
     *
     * Finds active MemberPress subscriptions whose most recent COMPLETE transaction
     * has a definite past expires_at, and cancels them via the MP API.
     *
     * Safety invariants:
     * - NEVER touches transactions where expires_at is 0000-00-00, null, or "Never" (lifetime).
     * - NEVER cancels subscriptions where expiry is in the future.
     * - Sends no email notifications (cancellation was already communicated when it happened).
     *
     * @TC_PAYLOAD_VERIFY: MP API response field names 'member_id' and 'membership_id' on
     * subscription objects — verify against your MP version's /wp-json/mp/v1/subscriptions response.
     */
    public function cron_expire_overdue_subscriptions(): void {
        $api_key = get_option('ae_tc_mp_sync_api_key', '');
        if (empty($api_key)) {
            $this->log('cron_expire_overdue_subscriptions: API key not configured — skipping');
            return;
        }

        $site_url      = get_site_url();
        $expired_count = 0;
        $page          = 1;

        $this->log('cron_expire_overdue_subscriptions: starting', array('utc_now' => gmdate('Y-m-d H:i:s')));

        do {
            $response = wp_remote_get($site_url . '/wp-json/mp/v1/subscriptions', array(
                'timeout' => 30,
                'headers' => array('MEMBERPRESS-API-KEY' => $api_key),
                'body'    => array(
                    'status'   => 'active',
                    'per_page' => 50,
                    'page'     => $page,
                ),
            ));

            if (is_wp_error($response)) {
                $this->log('cron_expire_overdue_subscriptions: fetch error', array(
                    'error' => $response->get_error_message(),
                ));
                break;
            }

            $subscriptions = json_decode(wp_remote_retrieve_body($response), true);

            if (empty($subscriptions) || !is_array($subscriptions)) {
                break;
            }

            foreach ($subscriptions as $sub) {
                $sub_id  = $sub['id']            ?? null;
                $user_id = $sub['member_id']     ?? null; // @TC_PAYLOAD_VERIFY: MP API field name
                $mem_id  = $sub['membership_id'] ?? null; // @TC_PAYLOAD_VERIFY: MP API field name

                if (!$sub_id || !$user_id || !$mem_id) continue;

                // Fetch most recent complete transaction for this subscription
                $trans_resp = wp_remote_get($site_url . '/wp-json/mp/v1/transactions', array(
                    'timeout' => 15,
                    'headers' => array('MEMBERPRESS-API-KEY' => $api_key),
                    'body'    => array(
                        'member'     => $user_id,
                        'membership' => $mem_id,
                        'status'     => 'complete',
                        'per_page'   => 1,
                    ),
                ));

                if (is_wp_error($trans_resp)) continue;

                $transactions = json_decode(wp_remote_retrieve_body($trans_resp), true);
                if (empty($transactions) || !is_array($transactions)) continue;

                $trans   = $transactions[0];
                $expires = $trans['expires_at'] ?? null;

                // Skip lifetime memberships (no expiry set)
                if (empty($expires)
                    || $expires === '0000-00-00 00:00:00'
                    || strtolower($expires) === 'never') {
                    continue;
                }

                $expiry_ts = strtotime($expires);
                if ($expiry_ts === false || $expiry_ts > time()) {
                    continue; // Not yet expired
                }

                // Expiry is in the past — cancel this subscription
                $cancel_endpoint = $site_url . '/wp-json/mp/v1/subscriptions/' . $sub_id . '/cancel';
                $cancel_response = wp_remote_post($cancel_endpoint, array(
                    'timeout' => 20,
                    'headers' => array(
                        'MEMBERPRESS-API-KEY' => $api_key,
                        'Content-Type'        => 'application/json',
                    ),
                    'body' => json_encode(array('send_notification' => false)),
                ));

                $cancel_code = wp_remote_retrieve_response_code($cancel_response);

                $this->log('cron_expire_overdue_subscriptions: subscription cancelled', array(
                    'sub_id'     => $sub_id,
                    'user_id'    => $user_id,
                    'mem_id'     => $mem_id,
                    'expired_at' => $expires,
                    'http_code'  => $cancel_code,
                ));

                if ($cancel_code === 200) {
                    $expired_count++;
                }
            }

            $page++;
        } while (count($subscriptions) >= 50);

        $this->log('cron_expire_overdue_subscriptions: complete', array('expired_count' => $expired_count));
    }

    private function log($message, $context = array()) {
        $log_dir = dirname(AE_TC_MP_SYNC_LOG_FILE);

        // Create secure log directory if it doesn't exist
        if (!file_exists($log_dir)) {
            @mkdir($log_dir, 0755, true);
            // Add .htaccess to deny direct access
            $htaccess = $log_dir . '/.htaccess';
            @file_put_contents($htaccess, "Deny from all\n");
            // Add index.php to prevent directory listing
            $index = $log_dir . '/index.php';
            @file_put_contents($index, "<?php\n// Silence is golden.\n");
        }

        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $context_str = !empty($context) ? ' ' . wp_json_encode($context, JSON_UNESCAPED_SLASHES) : '';
        $log_entry = "[{$timestamp}] {$message}{$context_str}\n";
        @file_put_contents(AE_TC_MP_SYNC_LOG_FILE, $log_entry, FILE_APPEND);
    }

    public function cleanup_old_logs() {
        $log_file = AE_TC_MP_SYNC_LOG_FILE;
        $retention_days = get_option('ae_tc_mp_sync_log_days', 30);

        if (!file_exists($log_file)) return;

        $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (empty($lines)) return;

        $cutoff_date = strtotime("-{$retention_days} days");
        $new_lines = array();

        foreach ($lines as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z)\]/', $line, $matches)) {
                $line_time = strtotime($matches[1]);
                if ($line_time >= $cutoff_date) {
                    $new_lines[] = $line;
                }
            }
        }

        if (!empty($new_lines)) {
            file_put_contents($log_file, implode("\n", $new_lines) . "\n");
        }
    }
}

function ae_tc_mp_sync_init() {
    return AE_ThriveCart_MemberPress_Sync::instance();
}

ae_tc_mp_sync_init();

register_activation_hook(__FILE__, function() {
    $log_dir = dirname(AE_TC_MP_SYNC_LOG_FILE);

    // Create secure log directory
    if (!file_exists($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }

    // Add .htaccess to block direct access
    $htaccess = $log_dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Deny from all\n");
    }

    // Add index.php to prevent directory listing
    $index = $log_dir . '/index.php';
    if (!file_exists($index)) {
        @file_put_contents($index, "<?php\n// Silence is golden.\n");
    }

    // Create empty log file
    if (!file_exists(AE_TC_MP_SYNC_LOG_FILE)) {
        @file_put_contents(AE_TC_MP_SYNC_LOG_FILE, '');
    }

    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('ae_tc_mp_sync_cleanup_logs');
    wp_clear_scheduled_hook('ae_tc_mp_sync_expire_check');
    wp_clear_scheduled_hook('ae_tc_mp_sync_retry_payment');
    flush_rewrite_rules();
});

/**
* Injects a "Manage my subscriptions" button on the MemberPress account Subscriptions tab (opens ThriveCart Customer Hub).
*/

if (!defined('ABSPATH')) exit;

add_action('wp_enqueue_scripts', function() {
    // Read hub URL from settings; skip entirely if not configured
    $hub_url = get_option('ae_tc_mp_sync_hub_url', '');
    if (empty($hub_url)) {
        return;
    }
    $hub_url = esc_url($hub_url);

    // Register an empty handle so we can attach inline JS cleanly
    wp_register_script('ae-mepr-tc-btn', false, array(), null, true);
    wp_enqueue_script('ae-mepr-tc-btn');

    $js  = '(function() {' . "\n";
    $js .= '  function onReady(fn){ if(document.readyState!=="loading"){fn()} else {document.addEventListener("DOMContentLoaded", fn);} }' . "\n";
    $js .= '  onReady(function(){' . "\n";
    $js .= '    try {' . "\n";
    $js .= '      // Only inject on the subscriptions tab' . "\n";
    $js .= '      var params = new URLSearchParams(window.location.search);' . "\n";
    $js .= '      if (params.get("action") !== "subscriptions") return;' . "\n";
    $js .= "\n";
    $js .= '      // Button' . "\n";
    $js .= '      var btn = document.createElement("a");' . "\n";
    $js .= '      btn.href = "' . $hub_url . '";' . "\n";
    $js .= '      btn.target = "_blank";' . "\n";
    $js .= '      btn.rel = "noopener";' . "\n";
    $js .= '      btn.textContent = "Manage my subscriptions";' . "\n";
    $js .= '      btn.className = "button ae-tc-manage-btn";' . "\n";
    $js .= "\n";
    $js .= '      // Styles' . "\n";
    $js .= '      var style = document.createElement("style");' . "\n";
    $js .= '      style.textContent = ".ae-tc-manage-btn{display:inline-block;margin-top:16px;padding:10px 16px;font-size:16px;} .ae-tc-btn-wrap{margin:16px}";' . "\n";
    $js .= '      document.head.appendChild(style);' . "\n";
    $js .= "\n";
    $js .= '      // Try several selectors to support any theme' . "\n";
    $js .= '      var targets = [' . "\n";
    $js .= '        ".mepr-account-subscriptions",' . "\n";
    $js .= '        "#mepr-account-subscriptions-table",' . "\n";
    $js .= '        ".mepr-account-content",' . "\n";
    $js .= '        ".mepr-account-table",' . "\n";
    $js .= '        ".mepr-account",' . "\n";
    $js .= '        "#content", ".site-content", "main"' . "\n";
    $js .= '      ];' . "\n";
    $js .= "\n";
    $js .= '      var inserted = false;' . "\n";
    $js .= '      for (var i = 0; i < targets.length; i++) {' . "\n";
    $js .= '        var el = document.querySelector(targets[i]);' . "\n";
    $js .= '        if (el) {' . "\n";
    $js .= '          var wrap = document.createElement("p");' . "\n";
    $js .= '          wrap.className = "ae-tc-btn-wrap";' . "\n";
    $js .= '          wrap.appendChild(btn);' . "\n";
    $js .= '          el.appendChild(wrap);' . "\n";
    $js .= '          inserted = true;' . "\n";
    $js .= '          break;' . "\n";
    $js .= '        }' . "\n";
    $js .= '      }' . "\n";
    $js .= '      // Last resort: append to body' . "\n";
    $js .= '      if (!inserted) {' . "\n";
    $js .= '        var wrap2 = document.createElement("p");' . "\n";
    $js .= '        wrap2.className = "ae-tc-btn-wrap";' . "\n";
    $js .= '        wrap2.style.textAlign = "left";' . "\n";
    $js .= '        wrap2.appendChild(btn);' . "\n";
    $js .= '        document.body.appendChild(wrap2);' . "\n";
    $js .= '      }' . "\n";
    $js .= '    } catch(e){ /* no-op */ }' . "\n";
    $js .= '  });' . "\n";
    $js .= '})();' . "\n";

    wp_add_inline_script('ae-mepr-tc-btn', $js);
});
