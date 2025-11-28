<?php
/**
 * Plugin Name: Spin Wheel for Woocommerce
 * Description: WooCommerce integrated spin wheel with Dynamic Slices and Order tracking.
 * Version: 3.0
 * Author: Ridwa.com
 * Author URI: https://ridwa.com
 */

if (!defined('ABSPATH')) exit;

// ---------------------------------------------------------
// 1. DATABASE & SETUP
// ---------------------------------------------------------
register_activation_hook(__FILE__, 'wpsw_install');

function wpsw_install() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'spin_wheel_results';
    $charset_collate = $wpdb->get_charset_collate();

    // Added 'customer_name' column
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        order_id varchar(50) NOT NULL,
        customer_name varchar(100) DEFAULT '' NOT NULL,
        phone_number varchar(50) NOT NULL,
        prize_won varchar(255) NOT NULL,
        is_paid tinyint(1) DEFAULT 0 NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY order_id (order_id) 
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Default Options
    if(!get_option('wpsw_config')) {
        $defaults = [
            'heading' => 'Spin the Wheel of Fortune!',
            'footer_html' => '<p>Contact us to claim your prize!</p><a href="#" class="button">Contact Support</a>',
            'slices' => [
				['text' => '100tk', 'color' => '#fcecbe', 'text_color' => '#ca0017', 'probability' => 40],
                ['text' => '200tk', 'color' => '#ca0017', 'text_color' => '#ffffff', 'probability' => 30],
                ['text' => '500tk', 'color' => '#fcecbe', 'text_color' => '#ca0017', 'probability' => 15],
                ['text' => '1000tk', 'color' => '#ca0017', 'text_color' => '#ffffff', 'probability' => 8],
                ['text' => '3000tk', 'color' => '#fcecbe', 'text_color' => '#ca0017', 'probability' => 4],
                ['text' => '5000tk', 'color' => '#ca0017', 'text_color' => '#ffffff', 'probability' => 2],
                ['text' => '8000tk', 'color' => '#fcecbe', 'text_color' => '#ca0017', 'probability' => 1],
                ['text' => '10000tk', 'color' => '#ca0017', 'text_color' => '#ffffff', 'probability' => 1]
            ]
        ];
        update_option('wpsw_config', $defaults);
    }
}

// ---------------------------------------------------------
// 2. ADMIN MENU & PAGES
// ---------------------------------------------------------
add_action('admin_menu', 'wpsw_admin_menu');

function wpsw_admin_menu() {
    add_menu_page('Spin Wheel', 'Spin Wheel', 'manage_options', 'wpsw-dashboard', 'wpsw_results_page', 'dashicons-chart-pie', 6);
    add_submenu_page('wpsw-dashboard', 'Results', 'Results', 'manage_options', 'wpsw-dashboard', 'wpsw_results_page');
    add_submenu_page('wpsw-dashboard', 'Settings', 'Settings', 'manage_options', 'wpsw-settings', 'wpsw_settings_page');
}

// --- A. RESULTS PAGE ---
function wpsw_results_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'spin_wheel_results';

    if (isset($_GET['action']) && $_GET['action'] == 'toggle_paid' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $current_status = $wpdb->get_var($wpdb->prepare("SELECT is_paid FROM $table_name WHERE id = %d", $id));
        $wpdb->update($table_name, ['is_paid' => !$current_status], ['id' => $id]);
        echo '<div class="notice notice-success"><p>Status updated.</p></div>';
    }

    $results = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Spin Wheel Results</h1>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Date</th>
                    <th>Customer Name</th>
                    <th>Order ID</th>
                    <th>Phone</th>
                    <th>Prize Won</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if($results): foreach($results as $row): ?>
                <tr>
                    <td><?php echo $row->id; ?></td>
                    <td><?php echo $row->created_at; ?></td>
                    <td><?php echo esc_html($row->customer_name); ?></td>
                    <td><a href="<?php echo get_edit_post_link($row->order_id); ?>" target="_blank">#<?php echo esc_html($row->order_id); ?></a></td>
                    <td><?php echo esc_html($row->phone_number); ?></td>
                    <td><strong><?php echo esc_html($row->prize_won); ?></strong></td>
                    <td>
                        <?php echo $row->is_paid ? '<span style="color:green;font-weight:bold;">COMPLETED</span>' : '<span style="color:red;">PENDING</span>'; ?>
                    </td>
                    <td>
                        <a href="?page=wpsw-dashboard&action=toggle_paid&id=<?php echo $row->id; ?>" class="button button-small">
                            <?php echo $row->is_paid ? 'Mark Pending' : 'Mark Completed'; ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="8">No spins recorded yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// --- B. SETTINGS PAGE (Dynamic Slices) ---
function wpsw_settings_page() {
    if (isset($_POST['wpsw_save_settings'])) {
        check_admin_referer('wpsw_save_settings', 'wpsw_nonce');

        $new_config = [
            'heading' => sanitize_text_field($_POST['heading']),
            'footer_html' => wp_kses_post($_POST['footer_html']), // Allow safe HTML
            'slices' => []
        ];

        if(isset($_POST['slice_text'])) {
            $count = count($_POST['slice_text']);
            for($i=0; $i<$count; $i++) {
                if(!empty($_POST['slice_text'][$i])) {
                    $new_config['slices'][] = [
                        'text' => sanitize_text_field($_POST['slice_text'][$i]),
                        'color' => sanitize_hex_color($_POST['slice_color'][$i]),
                        'text_color' => sanitize_hex_color($_POST['slice_text_color'][$i]),
                        'probability' => intval($_POST['slice_prob'][$i])
                    ];
                }
            }
        }
        update_option('wpsw_config', $new_config);
        echo '<div class="notice notice-success"><p>Settings Saved.</p></div>';
    }

    $config = get_option('wpsw_config');
    $slices = $config['slices'];
    ?>
    <div class="wrap">
        <h1>Spin Wheel Configuration</h1>
        <form method="post">
            <?php wp_nonce_field('wpsw_save_settings', 'wpsw_nonce'); ?>
            
            <!-- GENERAL SETTINGS -->
            <div class="card" style="max-width: 800px; padding: 20px; margin-bottom: 20px;">
                <h2>General Settings</h2>
                <table class="form-table">
                    <tr>
                        <th><label>Wheel Heading</label></th>
                        <td><input type="text" name="heading" value="<?php echo esc_attr($config['heading']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label>After Spin Content (HTML)</label></th>
                        <td>
                            <textarea name="footer_html" rows="5" class="large-text code"><?php echo esc_textarea($config['footer_html']); ?></textarea>
                            <p class="description">Add buttons, phone numbers, or instructions here. Use HTML (e.g., <code>&lt;a href="tel:123"&gt;Call Me&lt;/a&gt;</code>).</p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- SLICE SETTINGS -->
            <div class="card" style="max-width: 800px; padding: 20px;">
                <h2>Wheel Slices</h2>
                <p>Drag and drop is not supported yet, but you can Add/Delete slices. Probability is the "weight" of the slice.</p>
                
                <table class="widefat" id="slices-table">
                    <thead>
                        <tr>
                            <th>Text</th>
                            <th>Background</th>
                            <th>Text Color</th>
                            <th>Probability</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="slices-body">
                        <?php foreach($slices as $slice): ?>
                        <tr>
                            <td><input type="text" name="slice_text[]" value="<?php echo esc_attr($slice['text']); ?>" required></td>
                            <td><input type="color" name="slice_color[]" value="<?php echo esc_attr($slice['color']); ?>"></td>
                            <td><input type="color" name="slice_text_color[]" value="<?php echo esc_attr($slice['text_color']); ?>"></td>
                            <td><input type="number" name="slice_prob[]" value="<?php echo esc_attr($slice['probability']); ?>" min="0" style="width:60px;"></td>
                            <td><button type="button" class="button remove-row">Remove</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <br>
                <button type="button" class="button" id="add-slice"> + Add New Slice</button>
            </div>
            
            <p class="submit"><input type="submit" name="wpsw_save_settings" class="button button-primary button-hero" value="Save Changes"></p>
        </form>
    </div>

    <script>
    jQuery(document).ready(function($) {
        // Add Row
        $('#add-slice').click(function() {
            var row = '<tr>' +
                '<td><input type="text" name="slice_text[]" value="" required></td>' +
                '<td><input type="color" name="slice_color[]" value="#cccccc"></td>' +
                '<td><input type="color" name="slice_text_color[]" value="#000000"></td>' +
                '<td><input type="number" name="slice_prob[]" value="10" min="0" style="width:60px;"></td>' +
                '<td><button type="button" class="button remove-row">Remove</button></td>' +
                '</tr>';
            $('#slices-body').append(row);
        });

        // Remove Row
        $(document).on('click', '.remove-row', function() {
            if($('#slices-body tr').length > 1) {
                $(this).closest('tr').remove();
            } else {
                alert("You must have at least one slice.");
            }
        });
    });
    </script>
    <?php
}

// ---------------------------------------------------------
// 3. FRONTEND SHORTCODE
// ---------------------------------------------------------
add_shortcode('spin_wheel', 'wpsw_render_wheel');

function wpsw_render_wheel() {
    wp_enqueue_script('jquery');
    // Load Confetti Library via CDN
    wp_enqueue_script('canvas-confetti', 'https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js', [], null, true);

    $config = get_option('wpsw_config');
    
    // Pass config to JS
    $js_slices = [];
    foreach($config['slices'] as $s) {
        $js_slices[] = ['text' => $s['text'], 'color' => $s['color'], 'text_color' => $s['text_color']];
    }

    ob_start(); 
    ?>
    <style>
        /* COZY UI STYLES */
        .wpsw-wrapper {
            max-width: 500px;
            margin: 40px auto;
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            padding: 30px;
            font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            text-align: center;
            border: 1px solid #eee;
        }
        .wpsw-heading { font-size: 24px; font-weight: 800; color: #333; margin-bottom: 20px; text-transform: uppercase; letter-spacing: 1px; }
        
        /* CANVAS CONTAINER */
        .wheel-container {
            position: relative;
            margin-bottom: 25px;
            filter: drop-shadow(0 10px 15px rgba(0,0,0,0.15));
        }
        #wheel-canvas { width: 100%; height: auto; border-radius: 50%; }
        
        /* FORM INPUTS */
        .wpsw-input-group { display: flex; flex-direction: column; gap: 15px; margin-bottom: 20px; }
        .wpsw-input-group input {
            padding: 15px;
            border: 2px solid #eee;
            border-radius: 12px;
            font-size: 16px;
            width: 100%;
            box-sizing: border-box;
            transition: border-color 0.3s;
        }
        .wpsw-input-group input:focus { border-color: #2271b1; outline: none; }
        
        /* BUTTON */
        #wpsw_spin_btn {
            background: linear-gradient(135deg, #ff416c, #ff4b2b);
            color: white;
            border: none;
            padding: 16px;
            font-size: 18px;
            font-weight: bold;
            border-radius: 12px;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            box-shadow: 0 4px 15px rgba(255, 75, 43, 0.4);
            text-transform: uppercase;
        }
        #wpsw_spin_btn:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(255, 75, 43, 0.6); }
        #wpsw_spin_btn:disabled { background: #ccc; cursor: not-allowed; box-shadow: none; }

        /* RESULT & FOOTER */
        #wpsw-result-area { min-height: 20px; margin-top: 10px; }
        .wpsw-message { font-size: 18px; font-weight: bold; padding: 15px; border-radius: 8px; margin-bottom: 15px; }
        .wpsw-error { background: #ffe6e6; color: #d63031; border: 1px solid #fab1a0; }
        .wpsw-success { background: #e3fcef; color: #00b894; border: 1px solid #55efc4; font-size: 22px; }
        
        /* CUSTOM FOOTER HTML STYLES */
        .wpsw-footer-content { margin-top: 20px; padding-top: 20px; border-top: 1px dashed #eee; color: #555; }
        .wpsw-footer-content .button { 
            display: inline-block; text-decoration: none; background: #333; color: white; padding: 10px 20px; border-radius: 5px; margin-top:10px;
        }
    </style>

    <div class="wpsw-wrapper">
        <h2 class="wpsw-heading"><?php echo esc_html($config['heading']); ?></h2>
        
        <div class="wheel-container">
            <canvas id="wheel-canvas" width="600" height="600"></canvas>
            <!-- Center Hub -->
            <div style="position: absolute; top:50%; left:50%; width: 50px; height: 50px; background: white; border-radius:50%; transform: translate(-50%, -50%); box-shadow: 0 0 10px rgba(0,0,0,0.2); z-index: 5;"></div>
        </div>

        <div class="wpsw-input-group">
            <input type="text" id="wpsw_order_id" placeholder="Order ID (e.g. 1540)" required>
            <input type="text" id="wpsw_phone" placeholder="Billing Phone Number" required>
            <button id="wpsw_spin_btn">SPIN TO WIN</button>
        </div>

        <div id="wpsw-result-area"></div>
        
        <!-- Custom Footer (Hidden initially, shown after spin or check) -->
        <div id="wpsw-footer" class="wpsw-footer-content" style="display:none;">
            <?php echo wp_kses_post($config['footer_html']); ?>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        // --- CONFIGURATION ---
        const slices = <?php echo json_encode($js_slices); ?>;
        
        // Setup Canvas
        const canvas = document.getElementById("wheel-canvas");
        const ctx = canvas.getContext("2d");
        const size = 600; 
        const centerX = size / 2;
        const centerY = size / 2;
        const radius = size / 2 - 20;
        
        let currentAngle = 0;
        const arc = 2 * Math.PI / slices.length;
        let isSpinning = false;

        // --- SAFE COLOR HELPER ---
        // Prevents crashes if color is missing or invalid
        function adjustColor(color, amount) {
            if (!color || typeof color !== 'string' || color.length < 4) return '#cccccc'; // Fallback
            
            // Ensure hex format
            let hex = color.replace('#', '');
            if (hex.length === 3) hex = hex.split('').map(c => c + c).join(''); // Convert fff to ffffff
            
            const num = parseInt(hex, 16);
            if (isNaN(num)) return color; // Return original if invalid
            
            let r = (num >> 16) + amount;
            let g = ((num >> 8) & 0x00FF) + amount;
            let b = (num & 0x0000FF) + amount;

            return '#' + (
                0x1000000 +
                (r < 255 ? (r < 1 ? 0 : r) : 255) * 0x10000 +
                (g < 255 ? (g < 1 ? 0 : g) : 255) * 0x100 +
                (b < 255 ? (b < 1 ? 0 : b) : 255)
            ).toString(16).slice(1);
        }

        // --- DRAW WHEEL ---
        function drawWheel() {
            ctx.clearRect(0, 0, size, size);
            
            // 1. Draw Outer Ring
            ctx.beginPath();
            ctx.arc(centerX, centerY, radius + 15, 0, 2 * Math.PI);
            ctx.fillStyle = "#ffffff";
            ctx.fill();
            ctx.lineWidth = 5;
            ctx.strokeStyle = "#eee";
            ctx.stroke();

            for(let i = 0; i < slices.length; i++) {
                const angle = currentAngle + i * arc;
                
                // 2. Draw Slice
                ctx.beginPath();
                ctx.moveTo(centerX, centerY);
                ctx.arc(centerX, centerY, radius, angle, angle + arc);
                ctx.lineTo(centerX, centerY);
                
                // Safe Gradient Fill
                try {
                    let grd = ctx.createRadialGradient(centerX, centerY, radius * 0.2, centerX, centerY, radius);
                    grd.addColorStop(0, slices[i].color); 
                    grd.addColorStop(1, adjustColor(slices[i].color, -30)); // Darker rim
                    ctx.fillStyle = grd;
                } catch(e) {
                    // Fallback if gradient fails
                    ctx.fillStyle = slices[i].color || '#ccc';
                }
                
                ctx.fill();
                ctx.strokeStyle = "#ffffff";
                ctx.lineWidth = 2;
                ctx.stroke();
                
                // 3. Draw Text (Straight & Scaled)
                ctx.save();
                ctx.translate(centerX, centerY);
                ctx.rotate(angle + arc / 2); // Rotate to center of slice
                ctx.textAlign = "right";     // Align text to right (outer edge)
                ctx.textBaseline = "middle"; 
                ctx.fillStyle = slices[i].text_color;
                
                // Font Scaling Logic
                let fontSize = 28;
                ctx.font = 'bold ' + fontSize + 'px Arial';
                let textWidth = ctx.measureText(slices[i].text).width;
                let maxWidth = radius - 60; // Leave space for hub and rim

                // Shrink font if text is too long
                while (textWidth > maxWidth && fontSize > 12) {
                    fontSize--;
                    ctx.font = 'bold ' + fontSize + 'px Arial';
                    textWidth = ctx.measureText(slices[i].text).width;
                }

                // Draw Text with shadow
//                 ctx.shadowColor = "rgba(0,0,0,0.3)";
//                 ctx.shadowBlur = 4;
                ctx.fillText(slices[i].text, radius - 20, 0);
                
                ctx.restore();
            }

            // 4. Draw Pointer (Triangle)
            ctx.fillStyle = "#333";
            ctx.beginPath();
            ctx.moveTo(centerX - 20, centerY - (radius + 15));
            ctx.lineTo(centerX + 20, centerY - (radius + 15));
            ctx.lineTo(centerX, centerY - (radius - 10));
//             ctx.shadowColor = "rgba(0,0,0,0.2)";
//             ctx.shadowBlur = 10;
            ctx.fill();
//             ctx.shadowBlur = 0;
        }

        // Draw initially
        drawWheel();

        // --- SPIN BUTTON CLICK ---
        $('#wpsw_spin_btn').click(function() {
            const orderId = $('#wpsw_order_id').val();
            const phone = $('#wpsw_phone').val();
            
            if (!orderId || !phone) {
                alert("Please fill in all fields.");
                return;
            }

            if (isSpinning) return;
            
            $('#wpsw_spin_btn').prop('disabled', true).text("Processing...");
            $('#wpsw-result-area').html("");
            $('#wpsw-footer').hide();

            // AJAX Call
            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'wpsw_spin_action',
                    order_id: orderId,
                    phone: phone,
                    security: '<?php echo wp_create_nonce("wpsw_spin_nonce"); ?>'
                },
                success: function(response) {
                    console.log("Server Response:", response); // Debug Log

                    if(response.success) {
                        if(response.data.already_played) {
                            showResult("You've already played!<br>Prize: " + response.data.prize, 'error');
                            $('#wpsw_spin_btn').prop('disabled', false).text("Check Another Order");
                            $('#wpsw-footer').fadeIn();
                        } else {
                            // Valid - Start Animation
                            $('#wpsw_spin_btn').text("Spinning...");
                            isSpinning = true;
                            spinToWinner(response.data.index, response.data.prize);
                        }
                    } else {
                        showResult(response.data, 'error');
                        $('#wpsw_spin_btn').prop('disabled', false).text("SPIN TO WIN");
                    }
                },
                error: function(xhr, status, error) {
                    console.error("AJAX Error:", error);
                    showResult("Connection error. Please try again.", 'error');
                    $('#wpsw_spin_btn').prop('disabled', false).text("SPIN TO WIN");
                }
            });
        });

        // --- ANIMATION ---
        function spinToWinner(winningIndex, prizeText) {
            const sliceAngle = winningIndex * arc;
            const startAngle = currentAngle;
            // Stop Angle: current + 10 spins + alignment adjustment
            const endAngle = startAngle + (10 * Math.PI) + ( (1.5 * Math.PI) - (startAngle % (2*Math.PI)) - (sliceAngle + arc/2) ); 
            
            const duration = 5000;
            const startTime = performance.now();

            function animate(time) {
                const elapsed = time - startTime;
                const t = Math.min(1, elapsed / duration);
                // Ease Out Quart
                const easeOut = 1 - Math.pow(1 - t, 4);
                
                currentAngle = startAngle + (endAngle - startAngle) * easeOut;
                
                try {
                    drawWheel();
                } catch(e) {
                    console.error("Drawing error:", e);
                }

                if (t < 1) {
                    requestAnimationFrame(animate);
                } else {
                    isSpinning = false;
                    $('#wpsw_spin_btn').text("Spin Completed");
                    showResult("Congratulations!<br>You Won: " + prizeText, 'success');
                    $('#wpsw-footer').fadeIn();
                    triggerConfetti();
                }
            }
            requestAnimationFrame(animate);
        }

        function showResult(msg, type) {
            const cls = type === 'success' ? 'wpsw-success' : 'wpsw-error';
            $('#wpsw-result-area').html(`<div class="wpsw-message ${cls}">${msg}</div>`);
        }

        function triggerConfetti() {
            if(typeof confetti !== 'undefined') {
                confetti({
                    particleCount: 150,
                    spread: 70,
                    origin: { y: 0.6 }
                });
            }
        }
    });
    </script>
    <?php
    return ob_get_clean();
}

// ---------------------------------------------------------
// 4. SERVER SIDE LOGIC (SECURE VERSION)
// ---------------------------------------------------------
add_action('wp_ajax_wpsw_spin_action', 'wpsw_handle_spin');
add_action('wp_ajax_nopriv_wpsw_spin_action', 'wpsw_handle_spin');

function wpsw_handle_spin() {
    check_ajax_referer('wpsw_spin_nonce', 'security');
    
    $order_id = sanitize_text_field($_POST['order_id']);
    $phone = sanitize_text_field($_POST['phone']);

    if (empty($order_id) || empty($phone)) wp_send_json_error('Missing inputs.');

    // 1. VALIDATE WOOCOMMERCE ORDER FIRST
    if (!class_exists('WooCommerce')) wp_send_json_error('WooCommerce required.');
    
    $order = wc_get_order($order_id);
    
    if (!$order) {
        wp_send_json_error('Order ID not found.');
    }

    if ($order->get_status() !== 'completed') {
        wp_send_json_error('Order is not Completed.');
    }

    // 2. VALIDATE PHONE NUMBER (SECURITY CHECK)
    // Remove all non-numeric characters for comparison
    $order_phone = preg_replace('/[^0-9]/', '', $order->get_billing_phone());
    $input_phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Check if input phone matches order phone
    if (empty($input_phone) || (strpos($order_phone, $input_phone) === false && strpos($input_phone, $order_phone) === false)) {
        wp_send_json_error('Phone number does not match this Order ID.');
    }

    // 3. CHECK IF ALREADY SPUN 
    global $wpdb;
    // --- FIX: UPDATED TABLE NAME TO MATCH INSTALL FUNCTION ---
    $table_name = $wpdb->prefix . 'spin_wheel_results'; 
    
    $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE order_id = %s", $order_id));
    
    if ($existing) {
        wp_send_json_success(['already_played' => true, 'prize' => $existing->prize_won]);
        wp_die(); // Always exit after sending JSON
    }

    // 4. CALCULATE NEW WINNER
    $customer_name = $order->get_formatted_billing_full_name();
    $config = get_option('wpsw_config');
    $slices = $config['slices'];
    
    $total_weight = 0;
    foreach ($slices as $slice) $total_weight += intval($slice['probability']);
    
    $rand = mt_rand(1, $total_weight);
    $current_weight = 0;
    $winning_index = 0;
    $prize = '';

    foreach ($slices as $index => $slice) {
        $current_weight += intval($slice['probability']);
        if ($rand <= $current_weight) {
            $winning_index = $index;
            $prize = $slice['text'];
            break;
        }
    }

    // 5. SAVE RESULT
    $inserted = $wpdb->insert(
        $table_name,
        [
            'order_id' => $order_id,
            'customer_name' => $customer_name,
            'phone_number' => $phone,
            'prize_won' => $prize,
            'is_paid' => 0, // Ensure default value
            'created_at' => current_time('mysql')
        ],
        ['%s', '%s', '%s', '%s', '%d', '%s'] // Data formats
    );

    if ($inserted === false) {
        // Return DB error if insert fails
        wp_send_json_error('Database Error: Could not save result.');
    } else {
        wp_send_json_success(['already_played' => false, 'index' => $winning_index, 'prize' => $prize]);
    }
    
    wp_die();
}
