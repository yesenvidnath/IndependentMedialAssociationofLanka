<?php
/** Enable W3 Total Cache */
define('WP_CACHE', true); // Added by W3 Total Cache

/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'wordpress' );

/** Database username */
define( 'DB_USER', 'root' );

/** Database password */
define( 'DB_PASSWORD', '1234' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         'ysRNPOUfzdNvKKk9rlcK>1[4g?J Ej`Fo`M<):JPM.QoSO5| 1mU4sa}.+*E1H4r' );
define( 'SECURE_AUTH_KEY',  '<KdQ~ZR@5`fL.%k=Uu!0 H5Rn9(8Fwl6R0B G8m*}I!;HUV*y+=b]Ip{FNkkO}==' );
define( 'LOGGED_IN_KEY',    'VEi;oV7viOa%*zw0*#%s^U=Y;X(%`q0X4O>hWCK)f5L?,M$*[&7Q/JbsWs@XW?Y/' );
define( 'NONCE_KEY',        'G>TjDlfE0tt2E_?tZ)5-w_baIP:{x8cMQeOKwuK,8~zUD0I[SPq;Gy8 yB}^87v`' );
define( 'AUTH_SALT',        '{z8FSn83y-mus~I$6,ZANr8 )in=+_~sSQZiA9S$|2rc(346Rgcj;/k4)6m[[pF=' );
define( 'SECURE_AUTH_SALT', 'NYfV#!QJ~N&ixdXpk)C9c5+rN;$1P}cj>3iv=kqwB`2adx~0vHiIJ^v+._U4clw,' );
define( 'LOGGED_IN_SALT',   ']1Y{-8LDUO%qvH*eR+3i?1gv~VwlD#8o1CDs}C: 5Ho _?nl[sfqb}LZmr*[-5*u' );
define( 'NONCE_SALT',       'lsgL?Y7BW:X{`>bGeGt,sl)n/W0ilzI1:(.[gZ>lh=AeAT574(q5 =r0~C,PdE-w' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 *
 * At the installation time, database tables are created with the specified prefix.
 * Changing this value after WordPress is installed will make your site think
 * it has not been installed.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#table-prefix
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */
define( 'WP_DEBUG', false );

/* Add any custom values between this line and the "stop editing" line. */



/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
