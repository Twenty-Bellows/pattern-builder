<?php

if ( ! function_exists( 'pb_fs' ) ) {

    function pb_fs() {

        global $pb_fs;

        if ( ! isset( $pb_fs ) ) {
            $pb_fs = fs_dynamic_init( array(
                'id'                  => '19056',
                'slug'                => 'pattern-builder',
                'type'                => 'plugin',
                'public_key'          => 'pk_d2b48b9343ac970336f401830cd52',
                'is_premium'          => true,
                'has_premium_version' => true,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'menu'                => array(
                    'slug'           => 'pattern-builder',
                    'contact'        => false,
                    'support'        => false,
                    'parent'         => array(
                        'slug' => 'themes.php',
                    ),
                ),
            ) );
        }

        return $pb_fs;
    }

    // Init Freemius.
    pb_fs();
    // Signal that SDK was initiated.
    do_action( 'pb_fs_loaded' );
}
