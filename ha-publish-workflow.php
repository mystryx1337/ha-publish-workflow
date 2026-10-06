<?php
/**
 * Plugin Name: HA Publish Workflow
 * Description: Custom publish workflow that restricts specific categories/terms to certain roles and triggers review emails.
 * Version: 1.6.0
 * Author: Alexander Hacker
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Initialize Plugin Update Checker for automatic updates from GitHub repository.
 *
 * @return \YahnisElsts\PluginUpdateChecker\v5\Vcs\PluginUpdateChecker|null
 */
function ha_publish_workflow_get_updater() {
    static $update_checker = null;
    if ( null !== $update_checker ) {
        return $update_checker;
    }

    $puc_file = plugin_dir_path( __FILE__ ) . 'vendor/plugin-update-checker/plugin-update-checker.php';
    if ( ! file_exists( $puc_file ) ) {
        return null;
    }

    require_once $puc_file;

    $update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/mystryx1337/ha-publish-workflow/',
        __FILE__,
        'ha-publish-workflow'
    );

    // Set default branch to main
    $update_checker->setBranch( 'main' );

    // Set GitHub Access Token (if defined/available)
    $token = defined( 'HA_GITHUB_UPDATE_TOKEN' ) ? HA_GITHUB_UPDATE_TOKEN : '';
    if ( empty( $token ) && defined( 'GITHUB_ACCESS_TOKEN' ) ) {
        $token = GITHUB_ACCESS_TOKEN;
    }
    $token = apply_filters( 'ha_github_update_token', $token, 'ha-publish-workflow' );

    if ( ! empty( $token ) ) {
        $update_checker->setAuthentication( $token );
    }

    return $update_checker;
}
ha_publish_workflow_get_updater();


/**
 * 1. Register Admin Menu and Settings
 */
add_action( 'admin_menu', 'ha_pw_add_settings_page' );
add_action( 'admin_init', 'ha_pw_register_settings' );

function ha_pw_add_settings_page() {
    add_options_page(
        'Publish Workflow',
        'Publish Workflow',
        'manage_options',
        'ha-publish-workflow',
        'ha_pw_render_settings_page'
    );
}

function ha_pw_register_settings() {
    register_setting( 'ha_pw_options_group', 'ha_pw_rules', 'ha_pw_sanitize_rules' );
}

/**
 * Sanitize the repeater fields before saving to database
 */
function ha_pw_sanitize_rules( $input ) {
    $sanitized_rules = array();
    
    if ( is_array( $input ) ) {
        foreach ( $input as $rule ) {
            if ( ! empty( $rule['term_id'] ) && ! empty( $rule['role'] ) ) {
                $sanitized_rules[] = array(
                    'term_id' => absint( $rule['term_id'] ),
                    'role'    => sanitize_text_field( $rule['role'] ),
                    'email'   => sanitize_email( $rule['email'] ),
                );
            }
        }
    }
    
    return $sanitized_rules;
}

/**
 * 2. Render Settings Page UI
 */
function ha_pw_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    
    $rules = get_option( 'ha_pw_rules', array() );
    $roles = wp_roles()->get_names();
    
    $grouped_terms = array();
    $taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
    
    foreach ( $taxonomies as $tax ) {
        $terms = get_terms( array(
            'taxonomy'   => $tax->name,
            'hide_empty' => false,
        ) );
        
        if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
            $grouped_terms[ $tax->label ] = $terms;
        }
    }
    ?>
    <div class="wrap">
        <h1>Publish Workflow Settings</h1>
        <p>Define which roles are allowed to publish specific categories/terms. If a user without the role selects the category, the post will be set to "Pending Review" and an email will be sent.</p>
        
        <form method="post" action="options.php">
            <?php settings_fields( 'ha_pw_options_group' ); ?>
            
            <table class="wp-list-table widefat fixed striped" style="margin-top: 20px; max-width: 1000px;">
                <thead>
                    <tr>
                        <th style="width: 35%;">Term (Category / Taxonomy)</th>
                        <th style="width: 20%;">Required Role</th>
                        <th style="width: 30%;">Primary Email (Optional)</th>
                        <th style="width: 15%;">Actions</th>
                    </tr>
                </thead>
                <tbody id="ha-pw-rules-body">
                    <?php 
                    $index = 0;
                    if ( ! empty( $rules ) ) {
                        foreach ( $rules as $rule ) {
                            ha_pw_render_row( $index, $rule, $roles, $grouped_terms );
                            $index++;
                        }
                    } else {
                        ha_pw_render_row( 0, array(), $roles, $grouped_terms );
                        $index++;
                    }
                    ?>
                </tbody>
            </table>
            
            <p>
                <button type="button" class="button button-secondary" id="ha-pw-add-row" style="margin-top: 10px;">Add New Rule</button>
            </p>
            
            <?php submit_button(); ?>
        </form>

        <table style="display:none;">
            <tbody id="ha-pw-template-row">
                <?php ha_pw_render_row( '__INDEX__', array(), $roles, $grouped_terms, true ); ?>
            </tbody>
        </table>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var tableBody = document.getElementById('ha-pw-rules-body');
        var addBtn = document.getElementById('ha-pw-add-row');
        var rowCount = <?php echo intval( $index ); ?>;

        function initTermFilter(selectElement) {
            if (selectElement.hasAttribute('data-filtered')) return;
            selectElement.setAttribute('data-filtered', 'true');

            var input = document.createElement('input');
            input.type = 'text';
            input.placeholder = 'Filter terms...';
            input.style.width = '100%';
            input.style.marginBottom = '5px';
            input.style.boxSizing = 'border-box';

            selectElement.parentNode.insertBefore(input, selectElement);
            var originalHTML = selectElement.innerHTML;

            input.addEventListener('input', function() {
                var filterValue = this.value.toLowerCase();
                selectElement.innerHTML = originalHTML;

                if (!filterValue) return;

                var optgroups = selectElement.querySelectorAll('optgroup');
                optgroups.forEach(function(group) {
                    var options = group.querySelectorAll('option');
                    var hasMatch = false;

                    options.forEach(function(opt) {
                        if (opt.textContent.toLowerCase().indexOf(filterValue) > -1) {
                            hasMatch = true;
                        } else {
                            opt.remove();
                        }
                    });

                    if (!hasMatch) {
                        group.remove();
                    }
                });
            });
        }

        document.querySelectorAll('.ha-pw-term-select').forEach(initTermFilter);

        addBtn.addEventListener('click', function() {
            var templateRow = document.getElementById('ha-pw-template-row').querySelector('tr').cloneNode(true);
            templateRow.innerHTML = templateRow.innerHTML.replace(/__INDEX__/g, rowCount);
            
            tableBody.appendChild(templateRow);
            initTermFilter(templateRow.querySelector('.ha-pw-term-select'));
            rowCount++;
        });

        tableBody.addEventListener('click', function(e) {
            if ( e.target.classList.contains('ha-pw-remove-row') ) {
                e.target.closest('tr').remove();
            }
        });
    });
    </script>
    <?php
}

function ha_pw_render_row( $index, $rule, $roles, $grouped_terms, $is_template = false ) {
    $term_id = isset( $rule['term_id'] ) ? $rule['term_id'] : '';
    $role    = isset( $rule['role'] ) ? $rule['role'] : '';
    $email   = isset( $rule['email'] ) ? $rule['email'] : '';
    ?>
    <tr>
        <td>
            <select name="ha_pw_rules[<?php echo esc_attr( $index ); ?>][term_id]" class="ha-pw-term-select" style="width: 100%;" required>
                <option value="">Select a term...</option>
                <?php foreach ( $grouped_terms as $tax_label => $terms ) : ?>
                    <optgroup label="<?php echo esc_attr( $tax_label ); ?>">
                        <?php foreach ( $terms as $term ) : ?>
                            <option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( $term_id, $term->term_id ); ?>>
                                <?php echo esc_html( $term->name ); ?> (ID: <?php echo esc_html( $term->term_id ); ?>)
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <select name="ha_pw_rules[<?php echo esc_attr( $index ); ?>][role]" style="width: 100%;" required>
                <option value="">Select a role...</option>
                <?php foreach ( $roles as $slug => $name ) : ?>
                    <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $role, $slug ); ?>><?php echo esc_html( $name ); ?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <input type="email" name="ha_pw_rules[<?php echo esc_attr( $index ); ?>][email]" value="<?php echo esc_attr( $email ); ?>" placeholder="workflow@domain.com" style="width: 100%;" />
        </td>
        <td>
            <button type="button" class="button ha-pw-remove-row">Remove</button>
        </td>
    </tr>
    <?php
}

/**
 * 3. Gutenberg Integration: Live UI update, status revert and native notices with multiple terms support
 */
add_action( 'admin_footer-post.php', 'ha_pw_enqueue_gutenberg_script' );
add_action( 'admin_footer-post-new.php', 'ha_pw_enqueue_gutenberg_script' );

function ha_pw_enqueue_gutenberg_script() {
    $rules = get_option( 'ha_pw_rules', array() );
    if ( empty( $rules ) ) {
        return;
    }

    $user = wp_get_current_user();
    $user_roles = (array) $user->roles;
    $roles_names = wp_roles()->get_names();

    // Map restricted term IDs to their required role names and term names
    $restricted_terms_data = array();
    foreach ( $rules as $rule ) {
        if ( ! in_array( $rule['role'], $user_roles, true ) ) {
            // Get translated role name
            $role_name = isset( $roles_names[ $rule['role'] ] ) ? translate_user_role( $roles_names[ $rule['role'] ] ) : $rule['role'];
            
            // Get the actual term name to display to the user
            $term_obj = get_term( $rule['term_id'] );
            $term_name = ( $term_obj && ! is_wp_error( $term_obj ) ) ? $term_obj->name : 'ID ' . $rule['term_id'];

            $restricted_terms_data[ $rule['term_id'] ] = array(
                'roleName' => $role_name,
                'termName' => $term_name,
            );
        }
    }

    if ( empty( $restricted_terms_data ) ) {
        return;
    }
    ?>
    <script>
    (function() {
        // Ensure wp.data exists (Gutenberg is active)
        if (!window.wp || !wp.data || !wp.data.subscribe) return;

        var restrictedData = <?php echo json_encode( $restricted_terms_data ); ?>;
        var restrictedTermIds = Object.keys(restrictedData).map(Number);
        
        var lastTermString = null; 
        var preRestrictionStatus = null; // Store original status to allow reverting
        var noticeId = 'ha-pw-review-notice';

        console.log('HA Publish Workflow: Script loaded. Watching for terms:', restrictedTermIds);

        wp.data.subscribe(function() {
            var editor = wp.data.select('core/editor');
            var coreSelect = wp.data.select('core');
            var notices = wp.data.select('core/notices');
            
            if (!editor || !coreSelect || !notices) return;

            var taxonomies = coreSelect.getTaxonomies ? coreSelect.getTaxonomies() : [];
            if (!taxonomies || taxonomies.length === 0) return;

            // Bypass review loop if the post is already published
            var currentPost = editor.getCurrentPost();
            if (currentPost && currentPost.status === 'publish') {
                return;
            }

            // Collect all assigned terms across all taxonomies
            var currentTerms = [];
            taxonomies.forEach(function(tax) {
                var restBase = tax.rest_base || tax.slug;
                var terms = editor.getEditedPostAttribute(restBase);
                if (Array.isArray(terms)) {
                    currentTerms = currentTerms.concat(terms);
                }
            });

            // Convert array to string for simple comparison of state changes
            var currentTermString = currentTerms.sort().join(',');

            // Only execute if taxonomy selection actually changed
            if (lastTermString !== currentTermString) {
                lastTermString = currentTermString;
                
                // Find ALL currently selected terms that are restricted
                var matchedTerms = currentTerms.filter(function(termId) {
                    return restrictedTermIds.indexOf(termId) !== -1;
                });

                var currentStatus = editor.getEditedPostAttribute('status');

                if (matchedTerms.length > 0) {
                    
                    // Build a unique array of messages for all affected categories
                    var uniqueMessages = [];
                    matchedTerms.forEach(function(termId) {
                        var data = restrictedData[termId];
                        var text = '"' + data.termName + '" an Rolle "' + data.roleName + '"';
                        if (uniqueMessages.indexOf(text) === -1) {
                            uniqueMessages.push(text);
                        }
                    });

                    // Combine messages with commas
                    var combinedList = uniqueMessages.join(', ');
                    var msg = 'Hinweis: Aufgrund der gewählten Kategorie(n) wird dieser Beitrag beim Speichern zur Freigabe eingereicht: ' + combinedList + '.';
                    
                    console.log('HA Publish Workflow: Restricted taxonomies selected. Forcing pending status.', uniqueMessages);
                    
                    // Display WordPress native notice in the editor
                    // We recreate the notice every time to ensure the text is updated if a category is added/removed
                    wp.data.dispatch('core/notices').removeNotice(noticeId);
                    wp.data.dispatch('core/notices').createNotice(
                        'warning', 
                        msg, 
                        { id: noticeId, isDismissible: false }
                    );
                    
                    // Force post status to pending
                    if (currentStatus !== 'pending') {
                        preRestrictionStatus = currentStatus;
                        wp.data.dispatch('core/editor').editPost({ status: 'pending' });
                    }
                } else {
                    // Remove the custom notice if terms are unchecked
                    wp.data.dispatch('core/notices').removeNotice(noticeId);

                    // Revert status only if we are currently forcing 'pending'
                    if (currentStatus === 'pending') {
                        var revertTo = preRestrictionStatus ? preRestrictionStatus : 'draft';
                        
                        // Edge case: If it was already pending from DB load, falling back to draft is safest
                        if (revertTo === 'pending') revertTo = 'draft';
                        
                        console.log('HA Publish Workflow: Restricted taxonomy removed. Reverting status to: ' + revertTo);
                        wp.data.dispatch('core/editor').editPost({ status: revertTo });
                    }
                }
            }
        });
    })();
    </script>
    <?php
}

/**
 * 4. REST API & Classic Save Interception
 */
add_filter( 'wp_insert_post_data', 'ha_pw_intercept_publish', 10, 2 );

function ha_pw_intercept_publish( $data, $postarr ) {
    if ( ! in_array( $data['post_status'], array( 'publish', 'future' ), true ) ) {
        return $data;
    }

    // Bypass review loop if the post was already published
    if ( ! empty( $postarr['ID'] ) ) {
        $old_post = get_post( $postarr['ID'] );
        if ( $old_post && $old_post->post_status === 'publish' ) {
            return $data;
        }
    }

    $rules = get_option( 'ha_pw_rules', array() );
    if ( empty( $rules ) ) {
        return $data;
    }

    $user = wp_get_current_user();
    $user_roles = (array) $user->roles;

    $saving_terms = array();

    // Catch Classic Editor / standard taxonomy payload
    if ( isset( $postarr['post_category'] ) && is_array( $postarr['post_category'] ) ) {
        $saving_terms = array_merge( $saving_terms, $postarr['post_category'] );
    }

    // Catch custom taxonomy payloads
    if ( isset( $postarr['tax_input'] ) && is_array( $postarr['tax_input'] ) ) {
        foreach ( $postarr['tax_input'] as $terms ) {
            if ( is_array( $terms ) ) {
                $saving_terms = array_merge( $saving_terms, $terms );
            }
        }
    }
    
    // Gutenberg specific REST API fallback: Check REST bases
    $taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
    foreach ( $taxonomies as $tax ) {
        $rest_base = ! empty( $tax->rest_base ) ? $tax->rest_base : $tax->name;
        if ( isset( $postarr[ $rest_base ] ) && is_array( $postarr[ $rest_base ] ) ) {
            $saving_terms = array_merge( $saving_terms, $postarr[ $rest_base ] );
        }
    }

    // Include existing terms in case Gutenberg splits requests
    if ( ! empty( $postarr['ID'] ) ) {
        $existing_terms = wp_get_object_terms( 
            $postarr['ID'], 
            get_object_taxonomies( $data['post_type'] ), 
            array( 'fields' => 'ids' ) 
        );
        
        if ( ! is_wp_error( $existing_terms ) ) {
            $saving_terms = array_merge( $saving_terms, $existing_terms );
        }
    }

    $saving_terms = array_unique( array_map( 'intval', $saving_terms ) );
    $needs_review = false;

    foreach ( $rules as $rule ) {
        $term_id = (int) $rule['term_id'];
        
        if ( in_array( $term_id, $saving_terms, true ) ) {
            if ( ! in_array( $rule['role'], $user_roles, true ) ) {
                $needs_review = true;
                break;
            }
        }
    }

    if ( $needs_review ) {
        $data['post_status'] = 'pending';
    }

    return $data;
}

/**
 * 5. Trigger Notification Email
 */
add_action( 'transition_post_status', 'ha_pw_send_review_email', 10, 3 );

function ha_pw_send_review_email( $new_status, $old_status, $post ) {
    if ( 'pending' !== $new_status || 'pending' === $old_status ) {
        return;
    }

    $rules = get_option( 'ha_pw_rules', array() );
    if ( empty( $rules ) ) {
        return;
    }

    $taxonomies = get_object_taxonomies( $post->post_type );
    $terms = wp_get_object_terms( $post->ID, $taxonomies, array( 'fields' => 'ids' ) );

    if ( is_wp_error( $terms ) ) {
        return;
    }

    $user = wp_get_current_user();
    $user_roles = (array) $user->roles;
    $matched_rule = null;

    foreach ( $rules as $rule ) {
        $term_id = (int) $rule['term_id'];
        
        if ( in_array( $term_id, $terms, true ) ) {
            if ( ! in_array( $rule['role'], $user_roles, true ) ) {
                $matched_rule = $rule;
                break;
            }
        }
    }

    if ( $matched_rule ) {
        ha_pw_execute_email( $post, $matched_rule );
    }
}

function ha_pw_execute_email( $post, $rule ) {
    $to = '';

    if ( ! empty( $rule['email'] ) && is_email( $rule['email'] ) ) {
        $to = $rule['email'];
    } else {
        $users = get_users( array( 'role' => $rule['role'] ) );
        $emails = array();
        
        foreach ( $users as $u ) {
            if ( is_email( $u->user_email ) ) {
                $emails[] = $u->user_email;
            }
        }
        $to = implode( ',', $emails );
    }

    if ( empty( $to ) ) {
        return;
    }

    $author_name = get_the_author_meta( 'display_name', $post->post_author );
    $edit_link   = get_edit_post_link( $post->ID, 'raw' );
    $post_title  = get_the_title( $post->ID );
    
    if ( empty( $post_title ) ) {
        $post_title = '(No Title)';
    }

    $subject = 'Review Required: ' . $post_title;
    
    $message  = "A post requires your approval before publishing.\n\n";
    $message .= "Title: " . $post_title . "\n";
    $message .= "Author: " . $author_name . "\n";
    $message .= "Post Type: " . $post->post_type . "\n\n";
    $message .= "You can review and publish the post here:\n";
    $message .= $edit_link . "\n";

    wp_mail( $to, $subject, $message );
}