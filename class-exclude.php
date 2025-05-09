<?php

class FacetWP_Facet_Exclude_Addon extends FacetWP_Facet
{

    public $exclude = [];
    public $field_defaults;

    function __construct() {
        $this->label = __( 'Exclude', 'fwp' );
        $this->fields = [
            'ui_type',
            'label_any',
            'parent_term',
            'modifiers',
            'hierarchical',
            'show_expanded',
            'multiple',
            'ghosts',
            'orderby',
            'count',
            'soft_limit',
            'reverse_counts',
            'prefix',
            'suffix'
        ];
        $this->field_defaults = [
            'ui_type' => 'checkboxes'
        ];

        add_filter( 'facetwp_filtered_post_ids', [ $this, 'filter_post_ids' ], 11, 2 );
        add_filter( 'facetwp_facet_html', [ $this, 'hide_ghosts' ], 10, 2 );
        add_filter( 'facetwp_facet_render_args', [ $this, 'reverse_counts' ], 10 );
        add_filter( 'facetwp_facet_orderby', [ $this, 'reverse_orderby' ], 10, 2 );
        add_filter( 'facetwp_facet_display_value', [ $this, 'add_prefix_suffix' ], 10, 2 );
    }


    /**
     * Load the available choices
     */
    function load_values( $params ) {
        global $wpdb;

        $facet = $params['facet'];
        $from_clause = $wpdb->prefix . 'facetwp_index f';
        $where_clause = $params['where_clause'];

        // Orderby
        $orderby = $this->get_orderby( $facet );

        // Limit
        $limit = $this->get_limit( $facet );

        $orderby = apply_filters( 'facetwp_facet_orderby', $orderby, $facet );
        $from_clause = apply_filters( 'facetwp_facet_from', $from_clause, $facet );
        $where_clause = apply_filters( 'facetwp_facet_where', $where_clause, $facet );

        $sql = "
        SELECT f.facet_value, f.facet_display_value, f.term_id, f.parent_id, f.depth, COUNT(DISTINCT f.post_id) AS counter
        FROM $from_clause
        WHERE f.facet_name = '{$facet['name']}' $where_clause
        GROUP BY f.facet_value
        ORDER BY $orderby
        LIMIT $limit";

        $output = $wpdb->get_results( $sql, ARRAY_A );

        // Always include "ghost" facet choices. Hide them with hide_ghosts() when 'Show ghosts' is disabled
        // For performance gains, only run if facets are in use
        if ( FWP()->is_filtered ) {
            $raw_post_ids = implode( ',', FWP()->unfiltered_post_ids );

            $sql = "
            SELECT f.facet_value, f.facet_display_value, f.term_id, f.parent_id, f.depth, 0 AS counter
            FROM $from_clause
            WHERE f.facet_name = '{$facet['name']}' AND post_id IN ($raw_post_ids)
            GROUP BY f.facet_value
            ORDER BY $orderby
            LIMIT $limit";

            $ghost_output = $wpdb->get_results( $sql, ARRAY_A );
            $tmp = [];

            $preserve_ghosts = FWP()->helper->facet_is( $facet, 'preserve_ghosts', 'yes' );
            $orderby_count = FWP()->helper->facet_is( $facet, 'orderby', 'count' );

            // Keep the facet placement intact
            if ( $preserve_ghosts && ! $orderby_count ) {
                foreach ( $ghost_output as $row ) {
                    $tmp[ $row['facet_value'] . ' ' ] = $row;
                }

                foreach ( $output as $row ) {
                    $tmp[ $row['facet_value'] . ' ' ] = $row;
                }

                $output = $tmp;
            } else {
                // Make the array key equal to the facet_value (for easy lookup)
                foreach ( $output as $row ) {
                    $tmp[ $row['facet_value'] . ' ' ] = $row; // Force a string array key
                }

                $output = $tmp;

                foreach ( $ghost_output as $row ) {
                    $facet_value = $row['facet_value'];
                    if ( ! isset( $output["$facet_value "] ) ) {
                        $output["$facet_value "] = $row;
                    }
                }
            }

            $output = array_splice( $output, 0, $limit );
            $output = array_values( $output );
        }

        return $output;
    }


    /**
     * Generate the facet HTML
     */
    function render( $params ) {
        return FWP()->helper->facet_types['checkboxes']->render( $params );
    }


    /**
     * Get post_ids to filter out: OR mode
     */
    function filter_posts( $params ) {
        global $wpdb;

        $facet = $params['facet'];
        $selected_values = $params['selected_values'];

        if ( ! empty( $selected_values ) ) {

            $sql = $wpdb->prepare( "SELECT DISTINCT post_id
                FROM {$wpdb->prefix}facetwp_index
                WHERE facet_name = %s",
                $facet['name']
            );

            $selected_values = implode( "','", $selected_values );
            $this->exclude = $wpdb->get_col( $sql . " AND facet_value IN ('$selected_values')" );

        }

        return 'continue';
    }


    /**
     * Filter by removing matching posts
     * */
    function filter_post_ids( $post_ids, $class ) {

        if ( ! empty( $this->exclude ) ) {
            $post_ids = array_diff( $post_ids, $this->exclude );
        }

        return $post_ids;
    }


    /**
     * Hide ghosts if Show ghosts is disabled
     */

    function hide_ghosts( $output, $params ) {

        if ( 'exclude' == $params['facet']['type'] ) {
            if ( $params['facet']['ghosts'] == "no" ) {
                $css = '<style>[data-type="exclude"] .disabled { display: none; }</style>';
                $output = $css . $output;
            };
        }

        return $output;

    }


    /**
     * Reverse counts
     */
    function reverse_counts( $args ) {

        $facet = $args['facet'];
        $reverse_counts = FWP()->helper->facet_is( $facet, 'reverse_counts', 'yes' );

        if ( 'exclude' == $facet['type'] && $reverse_counts ) {

            $found_posts = FWP()->facet->query->found_posts;
            $selected = $args['selected_values'];

            $args['values'] = array_map( function( $value ) use ( $found_posts, $selected ) {

                $reverse_count = $found_posts - $value['counter'];

                // Show reverse count if no selections, or if choice is not selected and not "0" (to keep Ghosts/disappear working)
                if ( $selected === [] ) {
                    $value['counter'] = $reverse_count;
                } else {
                    if ( $value['counter'] !== "0" || in_array( $value['facet_value'], $selected ) ) {
                        $value['counter'] = $reverse_count;
                    }
                }

                return $value;

            }, $args['values'] );
        }

        return $args;
    }


    /**
     * Reverse orderby
     */
    function reverse_orderby( $orderby, $facet ) {

        $reverse_counts = FWP()->helper->facet_is( $facet, 'reverse_counts', 'yes' );

        if ( 'exclude' == $facet['type'] && $reverse_counts ) {

            // Reverse order if "Sort by" is set to "Highest count" and "Reverse counts" is enabled.
            if ( strpos( $orderby, 'counter DESC' ) !== false ) {
                $orderby = str_replace( 'counter DESC', 'counter ASC', $orderby );
            }

        }

        return $orderby;
    }


    /**
     * Add prefix/suffix
     */
    function add_prefix_suffix( $label, $params ) {

        $facet = $params['facet'];

        if ( 'exclude' == $facet['type'] ) {

            $prefix = facetwp_i18n( $facet['prefix'] );
            $suffix = facetwp_i18n( $facet['suffix'] );

            if ( ! empty( $prefix ) ) {
                $label = $prefix . $label;
            }

            if ( ! empty( $suffix ) ) {
                $label = $label . $suffix;
            }
        }

        return $label;
    }


    /**
     * Output any front-end scripts
     */
    function front_scripts() {

        FWP()->display->json['expand'] = '[+]';
        FWP()->display->json['collapse'] = '[-]';

        $facets = FWP()->helper->get_facets_by( 'type', 'exclude' );
        $active_facets = array_keys( FWP()->display->active_facets );
        foreach ( $facets as $facet ) {
            if ( in_array( $facet['name'], $active_facets ) && isset( $facet['ui_type'] ) && '' != $facet['ui_type'] ) {
                $facet_class = FWP()->helper->facet_types[ $facet['ui_type'] ];
                if ( method_exists( $facet_class, 'front_scripts' ) ) {
                    $facet_class->front_scripts();
                }
            }
        }
    }


    /**
     * (Front-end) Attach settings to the AJAX response
     */
    function settings_js( $params ) {
        $expand = empty( $params['facet']['show_expanded'] ) ? 'no' : $params['facet']['show_expanded'];

        return [ 'show_expanded' => $expand ];
    }


    /**
     * Register settings
     */
    function register_fields() {
        return [
            'reverse_counts' => [
                'type'  => 'toggle',
                'label' => __( 'Reverse counts', 'fwp' ),
                'notes' => 'Make facet choice counts represent the number or posts <em>without</em> that choice.'
            ],
            'prefix'         => [
                'label' => __( 'Prefix', 'fwp' ),
                'notes' => 'Text that appears before each facet choice label.<br>E.g. "no&nbsp;[...]".',
            ],
            'suffix'         => [
                'label' => __( 'Suffix', 'fwp' ),
                'notes' => 'Text that appears after each facet choice label.<br>E.g. "[...]-free".',
            ]
        ];
    }

}