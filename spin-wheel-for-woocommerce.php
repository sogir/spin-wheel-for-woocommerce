<?php
/**
 * Plugin Name: Spin Wheel for Woocommerce
 * Description: WooCommerce integrated spin wheel with Drag & Drop Slices, Separate Win Messages, and Order tracking.
 * Version: 3.2
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

    // Default Options (Updated with 'label' and 'win_message')
    if(!get_option('wpsw_config')) {
        $defaults = [
            'heading' => 'Spin the Wheel of Fortune!',
            'footer_html' => '<p>Contact us to claim your prize!</p><a href="#" class="button">Contact Support</a>',
            'slices' => [
                [
                    'label' => '100tk', 
                    'win_message' => 'You Won 100 Taka Cashback!', 
                    'description' => 'Amount will be added to your wallet.',
                    'color' => '#fcecbe', 
                    'text_color' => '#ca0017', 
                    'probability' => 40
                ],
                [
                    'label' => '200tk', 
                    'win_message' => 'Awesome! 200 Taka Bonus!', 
                    'description' => 'Check your account balance.',
                    'color' => '#ca0017', 
                    'text_color' => '#ffffff', 
                    'probability' => 30
                ],
                [
                    'label' => 'No Luck', 
                    'win_message' => 'Oh no! No prize this time.', 
                    'description' => 'Try again with your next order!',
                    'color' => '#333333', 
                    'text_color' => '#ffffff', 
                    'probability' => 30
                ],
            ]
        ];
        update_option('wpsw_config', $defaults);
    }
}

// ---------------------------------------------------------
// 2. ADMIN MENU & ASSETS
// ---------------------------------------------------------
add_action('admin_menu', 'wpsw_admin_menu');
add_action('admin_enqueue_scripts', 'wpsw_admin_assets');

function wpsw_admin_menu() {
    add_menu_page('Spin Wheel', 'Spin Wheel', 'manage_options', 'wpsw-dashboard', 'wpsw_results_page', 'dashicons-chart-pie', 6);
    add_submenu_page('wpsw-dashboard', 'Results', 'Results', 'manage_options', 'wpsw-dashboard', 'wpsw_results_page');
    add_submenu_page('wpsw-dashboard', 'Settings', 'Settings', 'manage_options', 'wpsw-settings', 'wpsw_settings_page');
}

function wpsw_admin_assets($hook) {
    // Only load on our settings page for Drag & Drop
    if ($hook === 'spin-wheel_page_wpsw-settings') {
        wp_enqueue_script('jquery-ui-sortable');
    }
}

// --- A. RESULTS PAGE (Search & Pagination) ---
function wpsw_results_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'spin_wheel_results';

    // Handle Status Toggle
    if (isset($_GET['action']) && $_GET['action'] == 'toggle_paid' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $current_status = $wpdb->get_var($wpdb->prepare("SELECT is_paid FROM $table_name WHERE id = %d", $id));
        $wpdb->update($table_name, ['is_paid' => !$current_status], ['id' => $id]);
        echo '<div class="notice notice-success"><p>Status updated.</p></div>';
    }

    // Pagination & Search Setup
    $pagenum = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $limit = 20; 
    $offset = ($pagenum - 1) * $limit;
    $search_term = isset($_GET['s']) ? sanitize_text_field(trim($_GET['s'])) : '';
    
    $where_clause = "";
    $query_args = [];

    if (!empty($search_term)) {
        $where_clause = "WHERE order_id LIKE %s OR phone_number LIKE %s OR customer_name LIKE %s";
        $like_term = '%' . $wpdb->esc_like($search_term) . '%';
        $query_args[] = $like_term;
        $query_args[] = $like_term;
        $query_args[] = $like_term;
    }

    if (!empty($query_args)) {
        $total_items = $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM $table_name $where_clause", $query_args));
    } else {
        $total_items = $wpdb->get_var("SELECT COUNT(id) FROM $table_name");
    }
    $num_pages = ceil($total_items / $limit);

    $sql = "SELECT * FROM $table_name $where_clause ORDER BY created_at DESC LIMIT %d, %d";
    $query_args[] = $offset;
    $query_args[] = $limit;
    
    $results = $wpdb->get_results($wpdb->prepare($sql, $query_args));
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Spin Wheel Results</h1>
        
        <form method="get" style="float:right; margin-bottom: 10px;">
            <input type="hidden" name="page" value="wpsw-dashboard">
            <input type="search" name="s" value="<?php echo esc_attr($search_term); ?>" placeholder="Search Order ID or Phone">
            <input type="submit" id="search-submit" class="button" value="Search">
            <?php if(!empty($search_term)): ?>
                <a href="?page=wpsw-dashboard" class="button">Reset</a>
            <?php endif; ?>
        </form>
        <div style="clear:both;"></div>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th width="50">ID</th>
                    <th>Date</th>
                    <th>Customer Name</th>
                    <th>Order ID</th>
                    <th>Phone</th>
                    <th>Prize Won (Label)</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if($results): foreach($results as $row): ?>
                <tr>
                    <td><?php echo $row->id; ?></td>
                    <td><?php echo date('M d, Y h:i A', strtotime($row->created_at)); ?></td>
                    <td><?php echo esc_html($row->customer_name); ?></td>
                    <td><a href="<?php echo get_edit_post_link($row->order_id); ?>" target="_blank">#<?php echo esc_html($row->order_id); ?></a></td>
                    <td><?php echo esc_html($row->phone_number); ?></td>
                    <td><strong><?php echo esc_html($row->prize_won); ?></strong></td>
                    <td>
                        <?php echo $row->is_paid ? '<span style="color:green;font-weight:bold;">COMPLETED</span>' : '<span style="color:red;">PENDING</span>'; ?>
                    </td>
                    <td>
                        <a href="?page=wpsw-dashboard&action=toggle_paid&id=<?php echo $row->id; ?>&paged=<?php echo $pagenum; ?>&s=<?php echo esc_attr($search_term); ?>" class="button button-small">
                            <?php echo $row->is_paid ? 'Mark Pending' : 'Mark Completed'; ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="8">No results found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($num_pages > 1): ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo $total_items; ?> items</span>
                <?php
                echo paginate_links([
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => __('&laquo;'),
                    'next_text' => __('&raquo;'),
                    'total' => $num_pages,
                    'current' => $pagenum
                ]);
                ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

// --- B. SETTINGS PAGE (Drag & Drop + Separate Texts) ---
function wpsw_settings_page() {
    if (isset($_POST['wpsw_save_settings'])) {
        check_admin_referer('wpsw_save_settings', 'wpsw_nonce');

        $new_config = [
            'heading' => sanitize_text_field($_POST['heading']),
            'footer_html' => wp_kses_post($_POST['footer_html']), 
            'slices' => []
        ];

        if(isset($_POST['slice_label'])) {
            $count = count($_POST['slice_label']);
            for($i=0; $i<$count; $i++) {
                if(!empty($_POST['slice_label'][$i])) {
                    $new_config['slices'][] = [
                        'label' => sanitize_text_field($_POST['slice_label'][$i]),
                        'win_message' => sanitize_text_field($_POST['slice_win_msg'][$i]), // NEW
                        'description' => wp_kses_post($_POST['slice_desc'][$i]), 
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
    <style>
        /* Drag & Drop Styles */
        .wpsw-grab { cursor: grab; color: #999; font-size: 18px; line-height: 1; padding-top: 5px; }
        .wpsw-grab:hover { color: #2271b1; }
        .ui-sortable-helper { display: table; background: #fff; box-shadow: 0 5px 15px rgba(0,0,0,0.2); }
    </style>

    <div class="wrap">
        <h1>Spin Wheel Configuration</h1>
        <form method="post">
            <?php wp_nonce_field('wpsw_save_settings', 'wpsw_nonce'); ?>
            
            <div class="card" style="max-width: 900px; padding: 20px; margin-bottom: 20px;">
                <h2>General Settings</h2>
                <table class="form-table">
                    <tr>
                        <th><label>Wheel Heading</label></th>
                        <td><input type="text" name="heading" value="<?php echo esc_attr($config['heading']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label>After Spin Content (HTML)</label></th>
                        <td>
                            <textarea name="footer_html" rows="4" class="large-text code"><?php echo esc_textarea($config['footer_html']); ?></textarea>
                            <p class="description">Appears at the very bottom after results.</p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="card" style="max-width: 1000px; padding: 20px;">
                <h2>Wheel Slices (Drag to Reorder)</h2>
                <table class="widefat" id="slices-table">
                    <thead>
                        <tr>
                            <th width="20"></th> <!-- Handle -->
                            <th width="150">Wheel Label<br><small>(Short)</small></th>
                            <th>Win Message<br><small>(Main Title)</small></th>
                            <th>Description<br><small>(Subtitle)</small></th>
                            <th width="80">Colors</th>
                            <th width="60">Prob(%)</th>
                            <th width="60">Action</th>
                        </tr>
                    </thead>
                    <tbody id="slices-body">
                        <?php foreach($slices as $slice): 
                            // Backward compatibility for old "text" field
                            $label = isset($slice['label']) ? $slice['label'] : (isset($slice['text']) ? $slice['text'] : '');
                            $win_msg = isset($slice['win_message']) ? $slice['win_message'] : $label;
                        ?>
                        <tr>
                            <td><span class="dashicons dashicons-menu wpsw-grab"></span></td>
                            <td><input type="text" name="slice_label[]" value="<?php echo esc_attr($label); ?>" required style="width:100%"></td>
                            <td><input type="text" name="slice_win_msg[]" value="<?php echo esc_attr($win_msg); ?>" required style="width:100%"></td>
                            <td><textarea name="slice_desc[]" rows="2" style="width:100%"><?php echo esc_textarea(isset($slice['description']) ? $slice['description'] : ''); ?></textarea></td>
                            <td>
                                <div style="margin-bottom:2px;"><input type="color" name="slice_color[]" value="<?php echo esc_attr($slice['color']); ?>" title="Background"></div>
                                <div><input type="color" name="slice_text_color[]" value="<?php echo esc_attr($slice['text_color']); ?>" title="Text"></div>
                            </td>
                            <td><input type="number" name="slice_prob[]" value="<?php echo esc_attr($slice['probability']); ?>" min="0" style="width:60px;"></td>
                            <td><button type="button" class="button remove-row">X</button></td>
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
        // Initialize Sortable (Drag & Drop)
        $('#slices-body').sortable({
            handle: '.wpsw-grab',
            axis: 'y',
            placeholder: 'ui-state-highlight',
            helper: function(e, tr) {
                var $originals = tr.children();
                var $helper = tr.clone();
                $helper.children().each(function(index) {
                    $(this).width($originals.eq(index).width());
                });
                return $helper;
            }
        });

        // Add Row
        $('#add-slice').click(function() {
            var row = '<tr>' +
                '<td><span class="dashicons dashicons-menu wpsw-grab"></span></td>' +
                '<td><input type="text" name="slice_label[]" value="" placeholder="e.g. 10%" required style="width:100%"></td>' +
                '<td><input type="text" name="slice_win_msg[]" value="" placeholder="You Won 10%!" required style="width:100%"></td>' +
                '<td><textarea name="slice_desc[]" rows="2" style="width:100%"></textarea></td>' +
                '<td><div style="margin-bottom:2px;"><input type="color" name="slice_color[]" value="#cccccc"></div><div><input type="color" name="slice_text_color[]" value="#000000"></div></td>' +
                '<td><input type="number" name="slice_prob[]" value="10" min="0" style="width:60px;"></td>' +
                '<td><button type="button" class="button remove-row">X</button></td>' +
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
    wp_enqueue_script('canvas-confetti', 'https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js', [], null, true);

    $config = get_option('wpsw_config');
    
    // Pass config to JS including separate texts
    $js_slices = [];
    foreach($config['slices'] as $s) {
        // Fallbacks for older data structure
        $label = isset($s['label']) ? $s['label'] : (isset($s['text']) ? $s['text'] : '');
        $win_msg = isset($s['win_message']) ? $s['win_message'] : $label;
        
        $js_slices[] = [
            'label' => $label, 
            'win_msg' => $win_msg,
            'desc' => isset($s['description']) ? $s['description'] : '', 
            'color' => $s['color'], 
            'text_color' => $s['text_color']
        ];
    }

    ob_start(); 
    ?>
    <style>
        .wpsw-wrapper {
            max-width: 500px; margin: 40px auto; background: #ffffff; border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1); padding: 30px;
            font-family: 'Segoe UI', sans-serif; text-align: center; border: 1px solid #eee;
        }
        .wpsw-heading { font-size: 24px; font-weight: 800; color: #333; margin-bottom: 20px; text-transform: uppercase; }
        .wheel-container { position: relative; margin-bottom: 25px; filter: drop-shadow(0 10px 15px rgba(0,0,0,0.15)); }
        #wheel-canvas { width: 100%; height: auto; border-radius: 50%; }
        
        .wpsw-input-group { display: flex; flex-direction: column; gap: 15px; margin-bottom: 20px; }
        .wpsw-input-group input { padding: 15px; border: 2px solid #eee; border-radius: 12px; font-size: 16px; width: 100%; box-sizing: border-box; }
        .wpsw-input-group input:focus { border-color: #2271b1; outline: none; }
        
        #wpsw_spin_btn {
            background: linear-gradient(135deg, #ff416c, #ff4b2b); color: white; border: none; padding: 16px;
            font-size: 18px; font-weight: bold; border-radius: 12px; cursor: pointer; text-transform: uppercase;
            box-shadow: 0 4px 15px rgba(255, 75, 43, 0.4); transition: all 0.2s;
        }
        #wpsw_spin_btn:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(255, 75, 43, 0.6); }
        #wpsw_spin_btn:disabled { background: #ccc; cursor: not-allowed; box-shadow: none; }

        #wpsw-result-area { min-height: 20px; margin-top: 10px; }
        .wpsw-message { font-size: 20px; font-weight: bold; padding: 20px; border-radius: 12px; margin-bottom: 15px; line-height: 1.4; }
        .wpsw-error { background: #ffe6e6; color: #d63031; border: 1px solid #fab1a0; font-size: 16px; }
        .wpsw-success { background: #e3fcef; color: #00b894; border: 1px solid #55efc4; }
        .wpsw-prize-desc { display: block; margin-top: 10px; font-size: 15px; font-weight: normal; color: #444; }

        .wpsw-footer-content { margin-top: 20px; padding-top: 20px; border-top: 1px dashed #eee; color: #555; }
        .wpsw-footer-content .button { background: #333; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none; display: inline-block; margin-top: 10px; }
    </style>

    <div class="wpsw-wrapper">
        <h2 class="wpsw-heading"><?php echo esc_html($config['heading']); ?></h2>
        
        <div class="wheel-container">
            <canvas id="wheel-canvas" width="600" height="600"></canvas>
            <div style="position: absolute; top:50%; left:50%; width: 50px; height: 50px; background: white; border-radius:50%; transform: translate(-50%, -50%); box-shadow: 0 0 10px rgba(0,0,0,0.2); z-index: 5;"></div>
        </div>

        <div class="wpsw-input-group">
            <input type="text" id="wpsw_order_id" placeholder="Order ID (e.g. 1540)" required>
            <input type="text" id="wpsw_phone" placeholder="Billing Phone Number" required>
            <button id="wpsw_spin_btn">SPIN TO WIN</button>
        </div>

        <div id="wpsw-result-area"></div>
        
        <div id="wpsw-footer" class="wpsw-footer-content" style="display:none;">
            <?php echo wp_kses_post($config['footer_html']); ?>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        const slices = <?php echo json_encode($js_slices); ?>;
        const canvas = document.getElementById("wheel-canvas");
        const ctx = canvas.getContext("2d");
        const size = 600; 
        const centerX = size / 2;
        const centerY = size / 2;
        const radius = size / 2 - 20;
        let currentAngle = 0;
        const arc = 2 * Math.PI / slices.length;
        let isSpinning = false;

        function adjustColor(color, amount) {
            if (!color || color.length < 4) return '#cccccc';
            let hex = color.replace('#', '');
            if (hex.length === 3) hex = hex.split('').map(c => c + c).join('');
            const num = parseInt(hex, 16);
            if (isNaN(num)) return color; 
            let r = (num >> 16) + amount;
            let g = ((num >> 8) & 0x00FF) + amount;
            let b = (num & 0x0000FF) + amount;
            return '#' + (0x1000000 + (r < 255 ? (r < 1 ? 0 : r) : 255) * 0x10000 + (g < 255 ? (g < 1 ? 0 : g) : 255) * 0x100 + (b < 255 ? (b < 1 ? 0 : b) : 255)).toString(16).slice(1);
        }

        function drawWheel() {
            ctx.clearRect(0, 0, size, size);
            ctx.beginPath();
            ctx.arc(centerX, centerY, radius + 15, 0, 2 * Math.PI);
            ctx.fillStyle = "#ffffff";
            ctx.fill();
            ctx.lineWidth = 5;
            ctx.strokeStyle = "#eee";
            ctx.stroke();

            for(let i = 0; i < slices.length; i++) {
                const angle = currentAngle + i * arc;
                ctx.beginPath();
                ctx.moveTo(centerX, centerY);
                ctx.arc(centerX, centerY, radius, angle, angle + arc);
                ctx.lineTo(centerX, centerY);
                try {
                    let grd = ctx.createRadialGradient(centerX, centerY, radius * 0.2, centerX, centerY, radius);
                    grd.addColorStop(0, slices[i].color); 
                    grd.addColorStop(1, adjustColor(slices[i].color, -30)); 
                    ctx.fillStyle = grd;
                } catch(e) { ctx.fillStyle = slices[i].color || '#ccc'; }
                ctx.fill();
                ctx.strokeStyle = "#ffffff"; ctx.lineWidth = 2; ctx.stroke();
                
                ctx.save();
                ctx.translate(centerX, centerY);
                ctx.rotate(angle + arc / 2);
                ctx.textAlign = "right"; ctx.textBaseline = "middle"; 
                ctx.fillStyle = slices[i].text_color;
                
                // --- DRAWING LABEL (SHORT TEXT) ---
                let fontSize = 28;
                ctx.font = 'bold ' + fontSize + 'px Arial';
                let textWidth = ctx.measureText(slices[i].label).width;
                while (textWidth > (radius - 60) && fontSize > 12) {
                    fontSize--; ctx.font = 'bold ' + fontSize + 'px Arial';
                    textWidth = ctx.measureText(slices[i].label).width;
                }
                ctx.fillText(slices[i].label, radius - 20, 0);
                ctx.restore();
            }
            ctx.fillStyle = "#333";
            ctx.beginPath();
            ctx.moveTo(centerX - 20, centerY - (radius + 15));
            ctx.lineTo(centerX + 20, centerY - (radius + 15));
            ctx.lineTo(centerX, centerY - (radius - 10));
            ctx.fill();
        }

        drawWheel();

        $('#wpsw_spin_btn').click(function() {
            const orderId = $('#wpsw_order_id').val();
            const phone = $('#wpsw_phone').val();
            
            if (!orderId || !phone) { alert("Please fill in all fields."); return; }
            if (isSpinning) return;
            
            $('#wpsw_spin_btn').prop('disabled', true).text("Processing...");
            $('#wpsw-result-area').html("");
            $('#wpsw-footer').hide();

            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: { action: 'wpsw_spin_action', order_id: orderId, phone: phone, security: '<?php echo wp_create_nonce("wpsw_spin_nonce"); ?>' },
                success: function(response) {
                    if(response.success) {
                        if(response.data.already_played) {
                            // Find matching slice for previous win
                            const pastLabel = response.data.prize;
                            const match = slices.find(s => s.label === pastLabel);
                            
                            let mainMsg = match ? match.win_msg : pastLabel;
                            let desc = match && match.desc ? `<span class="wpsw-prize-desc">${match.desc}</span>` : '';

                            showResult(`You've already played!<br>${mainMsg} ${desc}`, 'error');
                            $('#wpsw_spin_btn').prop('disabled', false).text("Check Another Order");
                            $('#wpsw-footer').fadeIn();
                        } else {
                            $('#wpsw_spin_btn').text("Spinning...");
                            isSpinning = true;
                            // Pass indices and data to animation
                            spinToWinner(response.data.index, slices[response.data.index]);
                        }
                    } else {
                        showResult(response.data, 'error');
                        $('#wpsw_spin_btn').prop('disabled', false).text("SPIN TO WIN");
                    }
                },
                error: function() {
                    showResult("Connection error. Please try again.", 'error');
                    $('#wpsw_spin_btn').prop('disabled', false).text("SPIN TO WIN");
                }
            });
        });

        function spinToWinner(winningIndex, sliceData) {
            const sliceAngle = winningIndex * arc;
            const startAngle = currentAngle;
            const endAngle = startAngle + (10 * Math.PI) + ( (1.5 * Math.PI) - (startAngle % (2*Math.PI)) - (sliceAngle + arc/2) ); 
            const duration = 5000;
            const startTime = performance.now();

            function animate(time) {
                const elapsed = time - startTime;
                const t = Math.min(1, elapsed / duration);
                const easeOut = 1 - Math.pow(1 - t, 4);
                currentAngle = startAngle + (endAngle - startAngle) * easeOut;
                drawWheel();
                if (t < 1) {
                    requestAnimationFrame(animate);
                } else {
                    isSpinning = false;
                    $('#wpsw_spin_btn').text("Spin Completed");
                    
                    // --- SHOW WIN MESSAGE AND DESCRIPTION ---
                    let descHtml = sliceData.desc ? `<span class="wpsw-prize-desc">${sliceData.desc}</span>` : '';
                    showResult(`${sliceData.win_msg} ${descHtml}`, 'success');
                    
                    $('#wpsw-footer').fadeIn();
                    if(typeof confetti !== 'undefined') confetti({ particleCount: 150, spread: 70, origin: { y: 0.6 } });
                }
            }
            requestAnimationFrame(animate);
        }

        function showResult(msg, type) {
            const cls = type === 'success' ? 'wpsw-success' : 'wpsw-error';
            $('#wpsw-result-area').html(`<div class="wpsw-message ${cls}">${msg}</div>`);
        }
    });
    </script>
    <?php
    return ob_get_clean();
}

// ---------------------------------------------------------
// 4. SERVER SIDE LOGIC
// ---------------------------------------------------------
add_action('wp_ajax_wpsw_spin_action', 'wpsw_handle_spin');
add_action('wp_ajax_nopriv_wpsw_spin_action', 'wpsw_handle_spin');

function wpsw_handle_spin() {
    check_ajax_referer('wpsw_spin_nonce', 'security');
    
    $order_id = sanitize_text_field($_POST['order_id']);
    $phone = sanitize_text_field($_POST['phone']);

    if (empty($order_id) || empty($phone)) wp_send_json_error('Missing inputs.');

    if (!class_exists('WooCommerce')) wp_send_json_error('WooCommerce required.');
    
    $order = wc_get_order($order_id);
    if (!$order) wp_send_json_error('Order ID not found.');
    if ($order->get_status() !== 'completed') wp_send_json_error('Order is not Completed.');

    $order_phone = preg_replace('/[^0-9]/', '', $order->get_billing_phone());
    $input_phone = preg_replace('/[^0-9]/', '', $phone);
    
    if (empty($input_phone) || (strpos($order_phone, $input_phone) === false && strpos($input_phone, $order_phone) === false)) {
        wp_send_json_error('Phone number does not match this Order ID.');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'spin_wheel_results'; 
    $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE order_id = %s", $order_id));
    
    // Return the Prize Label if already played
    if ($existing) {
        wp_send_json_success(['already_played' => true, 'prize' => $existing->prize_won]);
        wp_die(); 
    }

    $customer_name = $order->get_formatted_billing_full_name();
    $config = get_option('wpsw_config');
    $slices = $config['slices'];
    
    $total_weight = 0;
    foreach ($slices as $slice) $total_weight += intval($slice['probability']);
    
    $rand = mt_rand(1, $total_weight);
    $current_weight = 0;
    $winning_index = 0;
    $prize_label = '';

    foreach ($slices as $index => $slice) {
        $current_weight += intval($slice['probability']);
        if ($rand <= $current_weight) {
            $winning_index = $index;
            // We save the 'label' (e.g., 100tk) to DB for reporting cleanliness
            $prize_label = isset($slice['label']) ? $slice['label'] : $slice['text'];
            break;
        }
    }

    $inserted = $wpdb->insert(
        $table_name,
        [
            'order_id' => $order_id,
            'customer_name' => $customer_name,
            'phone_number' => $phone,
            'prize_won' => $prize_label,
            'is_paid' => 0,
            'created_at' => current_time('mysql')
        ],
        ['%s', '%s', '%s', '%s', '%d', '%s']
    );

    if ($inserted === false) {
        wp_send_json_error('Database Error: Could not save result.');
    } else {
        // Return winning index so JS can look up the "Win Message"
        wp_send_json_success(['already_played' => false, 'index' => $winning_index, 'prize' => $prize_label]);
    }
    
    wp_die();
}
