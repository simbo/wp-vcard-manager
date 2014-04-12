<?php

class WPvCardManager {

    protected $file,        // path to main plugin file / __FILE__
              $prefix,      // textdomain / prefix
              $version,     // plugin version
              $path,        // /full/path/to/wp-content/plugins/plugin-dir/
              $url,         // full URL to plugin dir
              $upload_url,  // URL of vcard uploads folder
              $upload_dir,  // path to vcard uploads folder
              $i18n_dir;    // internationalization directory

    private static $instance = null; // main instance of plugin class

    public static function _() {
        if( self::$instance===null )
            self::$instance = new self(func_get_arg(0));
        return self::$instance;
    }

    private function __construct( $file ) {
        // retrieve wp plugin data values
        $plugin_data = get_file_data( $file, array(
            'Version'    => 'Version',
            'TextDomain' => 'Text Domain'
        ));
        // plugin properties
        $this->file     = $file;
        $this->prefix   = $plugin_data['TextDomain'];
        $this->version  = $plugin_data['Version'];
        $this->path     = plugin_dir_path($file);
        $this->url      = plugin_dir_url($file);
        $this->i18n_dir = dirname(plugin_basename($file)).'/lang/';
        // upload properties
        $wp_upload_dir = wp_upload_dir();
        $this->upload_url = $wp_upload_dir['baseurl'].'/vcards';;
        $this->upload_dir = $wp_upload_dir['basedir'].'/vcards';
        // hook initialization
        add_action( 'plugins_loaded', array($this,'init') );
    }

    public function init() {
        // i18n
        load_plugin_textdomain( $this->prefix, false, $this->i18n_dir );
        // hook posttype setups
        add_action( 'init', array($this,'register_posttypes') );
        // hook admin scripts and styles
        add_action( 'admin_enqueue_scripts', array($this,'admin_scriptsnstyles') );
        // hook custom meta fields
        add_action( 'cmb_render_vcard_attachments', array($this,'metabox_render_attachments'), 10, 2 );
        add_filter( 'cmb_validate_vcard_attachments', array($this,'metabox_validate_attachments') );
        // hook metabox setups
        add_filter( 'cmb_meta_boxes', array($this,'register_metaboxes') );
        // hook metabox initialization
        add_action( 'init', array($this,'initialize_metaboxes'), 9999 );
        // hook into save_post
        add_action('save_post', array($this,'save_post') );
        // hook plugin activation / deactivation
        register_activation_hook( $this->file, array($this,'activate') );
        register_deactivation_hook( $this->file, array($this,'deactivate') );
        // register shortcodes
        add_shortcode( 'hcard', array($this,'shortcode_hcard') );
        add_shortcode( 'vcard_url', array($this,'shortcode_vcard_url') );
        add_shortcode( 'vcard_link', array($this,'shortcode_vcard_link') );
        add_shortcode( 'qrcode_url', array($this,'shortcode_qrcode_url') );
        add_shortcode( 'qrcode_img', array($this,'shortcode_qrcode_img') );
    }

    // on plugin activation
    public function activate() {
    }

    // on plugin deactivation
    public function deactivate() {
    }

    // hook into admin_head
    public function admin_scriptsnstyles() {
        // additional wp-admin css
        wp_register_style( $this->prefix.'_styles', $this->url.'assets/css/styles.min.css', false, $this->version );
        wp_enqueue_style( $this->prefix.'_styles' );
        // additional wp-admin js
        wp_register_script( $this->prefix.'_script', $this->url.'assets/js/script.min.js', false, $this->version );
        wp_enqueue_script( $this->prefix.'_script' );
        // translated strings for javascript
        wp_localize_script( $this->prefix.'_script', $this->prefix.'_i18n', array(
            'confirm_remove_element' => __('Are you sure you want to remove this element?',$this->prefix),
        ) );
    }

    // hook into save_post
    public function save_post( $post_id ) {
        $post = get_post($post_id);
        // if post is a vcard and not in revision or autosave mode
        if( $post->post_type=='vcard' && !wp_is_post_revision($post_id) && !wp_is_post_autosave($post_id) ) {
            // if a post_name is given
            if( !empty($post->post_name) ) {
                // base filename by post_name
                $filename = $this->upload_dir.'/'.$post->post_name;
                // filenames to delete
                $rmfiles = array('.vcf','.png','_black.png','_white.png');
                // try to delete existing files
                foreach( $rmfiles as $rmfile ) {
                    if( is_file($filename.$rmfile) ) {
                        @unlink($filename.$rmfile);
                    }
                }
            }
            // if a post title is given
            if( !empty($post->post_title) ) {
                // remove this function from save_post to prevent infinite loop
                remove_action( 'save_post', array($this,'save_post') );
                // update post name by post title
                wp_update_post(array(
                    'ID' => $post_id,
                    'post_name' => sanitize_title($post->post_title),
                ));
                // re-add function to save_post
                add_action( 'save_post', array($this,'save_post') );
                // get new post data
                $post = get_post($post_id);
            }
            // if a post name is given
            if( !empty($post->post_name) ) {
                // new base filename by post_name
                $filename = $this->upload_dir.'/'.$post->post_name;
                // check writable state of upload dir, create if necessary
                if( wp_mkdir_p($this->upload_dir) && is_writable($this->upload_dir) ) {
                    // get vcard formatted data
                    $vcard_text = $this->get_vcard($post);
                    // write vcard to file
                    file_put_contents($filename.'.vcf',$vcard_text);
                    // get qrcode size
                    $qrcode_size = get_post_meta( $post->ID, '_'.$this->prefix.'_qrcode_size', true );
                    $qrcode_size = intval( empty($qrcode_size) ? 3 : $qrcode_size );
                    $qrcode_size = min(10,max(1,$qrcode_size));
                    // write qrcode image file from vcard data
                    QRcode::png( $vcard_text, $filename.'.png', QR_ECLEVEL_L, $qrcode_size, 0 );
                    // read qrcode image
                    if( $img = imagecreatefrompng($filename.'.png') ) {
                        // get dimensions
                        $img_width = imagesx($img);
                        $img_height = imagesy($img);
                        // create a image version where only white parts are visible
                        $white_img = imagecreatetruecolor($img_width,$img_height);
                        $white_img_transparency = imagecolorallocate($white_img, 0, 0, 0);
                        imagecolortransparent($white_img, $white_img_transparency);
                        imagecopy($white_img, $img, 0, 0, 0, 0, $img_width, $img_height);
                        imagepng($white_img, $filename.'_white.png',9);
                        // create a image version where only black parts are visible
                        $black_img = imagecreatetruecolor($img_width,$img_height);
                        $black_img_transparency = imagecolorallocate($black_img, 255, 255, 255);
                        imagecolortransparent($black_img, $black_img_transparency);
                        imagecopy($black_img, $img, 0, 0, 0, 0, $img_width, $img_height);
                        imagepng($black_img, $filename.'_black.png',9);
                    }
                }
            }
        }
    }

    // register custom post types
    public function register_posttypes() {
        $vcard_labels = array(
            'name'               => __( 'vCards', $this->prefix ),
            'singular_name'      => __( 'vCard', $this->prefix ),
            'add_new'            => __( 'Add New', $this->prefix ),
            'add_new_item'       => __( 'Add New vCard', $this->prefix ),
            'edit_item'          => __( 'Edit vCard', $this->prefix ),
            'new_item'           => __( 'New vCard', $this->prefix ),
            'all_items'          => __( 'All vCards', $this->prefix ),
            'view_item'          => __( 'View vCard', $this->prefix ),
            'search_items'       => __( 'Search vCards', $this->prefix ),
            'not_found'          => __( 'No vCards found', $this->prefix ),
            'not_found_in_trash' => __( 'No vCards found in trash', $this->prefix ),
            'menu_name'          => __( 'vCards', $this->prefix )
        );
        register_post_type( 'vcard', array(
            'label'                 => $vcard_labels['name'],
            'labels'                => $vcard_labels,
            'exclude_from_search'   => true,
            'publicly_queryable'    => false,
            'public'                => false,
            'show_ui'               => true,
            'show_in_menu'          => true,
            'show_in_nav_menus'     => false,
            'has_archive'           => false,
            'menu_position'         => 25,
            'menu_icon'             => '',
            'capability_type'       => 'post',
            'supports'              => array('title'),
        ));
    }

    // register custom meta boxes
    // wiki: https://github.com/WebDevStudios/Custom-Metaboxes-and-Fields-for-WordPress/wiki
    public function register_metaboxes( array $metaboxes ) {
        $prefix = '_'.$this->prefix;
        $metaboxes[$prefix.'_vcard'] = array(
            'id'         => $prefix.'_vcard',
            'title'      => __( 'vCard Properties', $this->prefix ),
            'pages'      => array( 'vcard', ), // Post type
            'context'    => 'normal',
            'priority'   => 'high',
            'show_names' => true,
            'cmb_styles' => true,
            'fields'     => array(
                array(
                    'name' => __( 'Full Name', $this->prefix ),
                    'desc' => __( 'The person\'s full name, defaults to vCard title', $this->prefix ),
                    'id'   => $prefix.'_fullname',
                    'type' => 'text',
                ),
                array(
                    'name' => __( 'Given Name', $this->prefix ),
                    'desc' => __( 'The person\'s first name', $this->prefix ),
                    'id'   => $prefix.'_givenname',
                    'type' => 'text',
                ),
                array(
                    'name' => __( 'Additional Name', $this->prefix ),
                    'desc' => __( 'The person\'s middle or second name', $this->prefix ),
                    'id'   => $prefix.'_additionalname',
                    'type' => 'text',
                ),
                array(
                    'name' => __( 'Family Name', $this->prefix ),
                    'desc' => __( 'The person\'s last name', $this->prefix ),
                    'id'   => $prefix.'_familyname',
                    'type' => 'text',
                ),
                array(
                    'name' => __( 'Prefix', $this->prefix ),
                    'desc' => __( 'Honorific prefixes', $this->prefix ),
                    'id'   => $prefix.'_prefix',
                    'type' => 'text',
                ),
                array(
                    'name' => __( 'Suffix', $this->prefix ),
                    'desc' => __( 'Honorific suffixes', $this->prefix ),
                    'id'   => $prefix.'_suffix',
                    'type' => 'text',
                ),
                array(
                    'name' => __( 'Nickname', $this->prefix ),
                    'desc' => __( 'The person\'s nickname or nicknames, comma-separated', $this->prefix ),
                    'id'   => $prefix.'_nick',
                    'type' => 'text',
                ),
                array(
                    'name' => __( 'Photo', $this->prefix ),
                    'desc' => __( 'A photo of the person', $this->prefix ),
                    'id'   => $prefix.'_photo',
                    'type' => 'file',
                ),
                array(
                    'name' => __( 'URL', $this->prefix ),
                    'desc' => __( 'A profile or homepage URL', $this->prefix ),
                    'id'   => $prefix.'_url',
                    'type' => 'text',
                ),
                array(
                    'name' => __( 'PGP Key', $this->prefix ),
                    'desc' => __( 'corresponding PGP/GnuPG public key URL', $this->prefix ),
                    'id'   => $prefix.'_pgpkey',
                    'type' => 'text',
                ),
                array(
                    'name' => __( 'Organization', $this->prefix ),
                    'desc' => __( 'Company or Organization', $this->prefix ),
                    'id'   => $prefix.'_organization',
                    'type' => 'text',
                ),
                array(
                    'name' => __( 'Title', $this->prefix ),
                    'desc' => __( 'The person\'s job title within the organization', $this->prefix ),
                    'id'   => $prefix.'_title',
                    'type' => 'text',
                ),
                array(
                    'name' => __( 'Role', $this->prefix ),
                    'desc' => __( 'The person\'s role within the organization', $this->prefix ),
                    'id'   => $prefix.'_role',
                    'type' => 'text',
                ),
                array(
                    'name' => __( 'Logo', $this->prefix ),
                    'desc' => __( 'The organization logo', $this->prefix ),
                    'id'   => $prefix.'_logo',
                    'type' => 'file',
                ),
                array(
                    'name' => __( 'Source', $this->prefix ),
                    'desc' => __( 'Source URL to vcard, defaults to vCard URL in uploads folder', $this->prefix ),
                    'id'   => $prefix.'_source',
                    'type' => 'text',
                ),
                array(
                    'id'          => $prefix.'_group_email',
                    'type'        => 'group',
                    'desc'        => __( 'E-Mail addresses', $this->prefix ),
                    'options'     => array(
                        'add_button'    => __( 'Add E-Mail', $this->prefix ),
                        'remove_button' => __( 'Remove E-Mail', $this->prefix ),
                        'sortable'      => true,
                    ),
                    'fields'      => array(
                        array(
                            'name'    => __( 'Type', $this->prefix ),
                            'id'      => 'type',
                            'type'    => 'multicheck',
                            'default' => array('home'),
                            'options' => array(
                                'home'   => __( 'Home', $this->prefix ),
                                'work'   => __( 'Work', $this->prefix ),
                            ),
                            'inline'  => true,
                        ),
                        array(
                            'name' => __( 'E-Mail', $this->prefix ),
                            'id'   => 'value',
                            'type' => 'text',
                        ),
                    ),
                ),
                array(
                    'id'          => $prefix.'_group_phone',
                    'type'        => 'group',
                    'desc'        => __( 'Phone Numbers', $this->prefix ),
                    'options'     => array(
                        'add_button'    => __( 'Add Number', $this->prefix ),
                        'remove_button' => __( 'Remove Number', $this->prefix ),
                        'sortable'      => true,
                    ),
                    'fields'      => array(
                        array(
                            'name'    => __( 'Type', $this->prefix ),
                            'id'      => 'type',
                            'type'    => 'multicheck',
                            'default' => array('home','cell','voice'),
                            'options' => array(
                                'home'   => __( 'Home', $this->prefix ),
                                'work'   => __( 'Work', $this->prefix ),
                                'cell'   => __( 'Mobile', $this->prefix ),
                                'voice'  => __( 'Voice', $this->prefix ),
                                'fax'    => __( 'Fax', $this->prefix ),
                            ),
                            'inline'  => true,
                        ),
                        array(
                            'name' => __( 'Number', $this->prefix ),
                            'id'   => 'value',
                            'type' => 'text',
                        ),
                    ),
                ),
                array(
                    'id'          => $prefix.'_group_addr',
                    'type'        => 'group',
                    'desc'        => __( 'Addresses', $this->prefix ),
                    'options'     => array(
                        'add_button'    => __( 'Add Address', $this->prefix ),
                        'remove_button' => __( 'Remove Address', $this->prefix ),
                        'sortable'      => true,
                    ),
                    'fields'      => array(
                        array(
                            'name'    => __( 'Type', $this->prefix ),
                            'id'      => 'type',
                            'type'    => 'multicheck',
                            'default' => array('work','postal','parcel','intl'),
                            'options' => array(
                                'home'   => __( 'Home', $this->prefix ),
                                'work'   => __( 'Work', $this->prefix ),
                                'dom'    => __( 'Domestic', $this->prefix ),
                                'intl'   => __( 'International', $this->prefix ),
                                'postal' => __( 'Postal', $this->prefix ),
                                'parcel' => __( 'Parcel', $this->prefix ),
                            ),
                            'inline'  => true,
                        ),
                        array(
                            'name' => __( 'P.O. box', $this->prefix ),
                            'id'   => 'pobox',
                            'type' => 'text',
                        ),
                        array(
                            'name' => __( 'Street', $this->prefix ),
                            'id'   => 'street',
                            'type' => 'text',
                        ),
                        array(
                            'name' => __( 'Extended', $this->prefix ),
                            'id'   => 'extended',
                            'type' => 'text',
                        ),
                        array(
                            'name' => __( 'Locality', $this->prefix ),
                            'id'   => 'locality',
                            'type' => 'text',
                        ),
                        array(
                            'name' => __( 'Postal Code', $this->prefix ),
                            'id'   => 'postalcode',
                            'type' => 'text',
                        ),
                        array(
                            'name' => __( 'Region', $this->prefix ),
                            'id'   => 'region',
                            'type' => 'text',
                        ),
                        array(
                            'name' => __( 'Country', $this->prefix ),
                            'id'   => 'country',
                            'type' => 'text',
                        ),
                        array(
                            'name' => __( 'Formatted', $this->prefix ),
                            'id'   => 'label',
                            'type' => 'textarea_small',
                        ),
                    ),
                ),
            ),
        );
        $metaboxes[$prefix.'_attachments'] = array(
            'id'         => $prefix.'_attachments',
            'title'      => __( 'Output', $this->prefix ),
            'pages'      => array( 'vcard', ), // Post type
            'context'    => 'normal',
            'priority'   => 'high',
            'show_names' => false,
            'cmb_styles' => true,
            'fields'     => array(
                array(
                    'name' => __( 'Output', $this->prefix ),
                    'description' => __( 'Data and files will be generated on save.', $this->prefix ),
                    'id'   => $prefix.'_attachments',
                    'type' => 'vcard_attachments',
                ),
                array(
                    'name' => __( 'QRCode square size', $this->prefix ),
                    'desc' => __( 'Pixel size of squares/dots in QRCode image', $this->prefix ),
                    'default' => '3',
                    'id'   => $prefix.'_qrcode_size',
                    'type' => 'text_small'
                ),
                array(
                    'name' => __( 'vCard Version', $this->prefix ),
                    'desc' => __( 'which vCard Version to use', $this->prefix ),
                    'default' => '21',
                    'id'      => $prefix.'_vcard_version',
                    'type'    => 'radio_inline',
                    'options' => array(
                        '21' => '2.1',
                        '30' => '3.0',
                    )
                ),
            ),
        );
        return $metaboxes;
    }

    // initialize custom metaboxes
    public function initialize_metaboxes() {
        if( !class_exists('cmb_Meta_Box') )
            require_once( $this->path.'vendor/metabox/init.php' );
    }

    // contents of the "Output" metabox
    public function metabox_render_attachments( $field, $value ) {
        global $post;
        // helpful description
        echo '<p class="cmb_metabox_description">'.$field['description'].'</p>';
        // if a postname is set / the post has been saved at least once
        if( !empty($post->post_name) ) {
            // generate vcard data
            $vcard = $this->get_vcard($post);
            // generate hcard html
            $hcard = $this->get_hcard($post);
            // base url
            $url = $this->upload_url.'/'.$post->post_name;
            // vcard file url
            $vcard_url = $url.'.vcf';
            // qrcode img url
            $qrcode_url = $url.'.png';
            // qrcode img url, only white parts visible
            $qrcodew_url = $url.'_white.png';
            // qrcode img url, only black parts visible
            $qrcodeb_url = $url.'_black.png';
            // html output
            echo '<div class="vcard output">'.
                    '<span class="output-title">'.
                        __('vCard',$this->prefix).
                        '<span class="spec">'.
                            '(<a href="http://www.rfcreader.com/#rfc2426">RFC</a>)'.
                        '</span>'.
                    '</span>'.
                    '<textarea readonly>'.$vcard.'</textarea>'.
                '</div>'.
                '<div class="hcard output">'.
                    '<span class="output-title">'.
                        __('hCard',$this->prefix).
                        '<span class="spec">'.
                            '(<a href="http://microformats.org/wiki/hcard">hcard</a>'.
                            ' / <a href="http://microformats.org/wiki/h-card">h-card</a>)'.
                        '</span>'.
                    '</span>'.
                    '<textarea readonly>'.htmlspecialchars($hcard).'</textarea>'.
                '</div>'.
                '<div class="clear"></div>'.
                '<img src="'.$qrcode_url.'?'.get_post_time('U',true,$post,true).'" style="float:right">'.
                '<p>'.
                    '<strong>'.__('hCard HTML',$this->prefix).'</strong>'.
                    ' <code>[hcard id='.$post->ID.']</code> / <code>[hcard name='.$post->post_name.']</code>'.
                '</p><p>'.
                    '<a href="'.$vcard_url.'" target="_blank">'.
                        '<strong>'.__('vCard Link',$this->prefix).'</strong>'.
                    '</a> <code>[vcard_link id='.$post->ID.']&hellip;[/vcard_link]</code> / <code>[vcard_link name='.$post->post_name.']&hellip;[/vcard_link]</code>'.
                '</p><p>'.
                    '<a href="'.$vcard_url.'" target="_blank">'.
                        '<strong>'.__('vCard URL',$this->prefix).'</strong>'.
                    '</a> <code>[vcard_url id='.$post->ID.']</code> / <code>[vcard_url name='.$post->post_name.']</code>'.
                '</p><p>'.
                    '<a href="'.$qrcode_url.'" target="_blank">'.
                       '<strong>'.__('QRCode Image',$this->prefix).'</strong>'.
                    '</a> <code>[qrcode_img id='.$post->ID.']</code> / <code>[qrcode_img name='.$post->post_name.']</code>'.
                '</p><p>'.
                    '<a href="'.$qrcode_url.'" target="_blank">'.
                       '<strong>'.__('QRCode URL',$this->prefix).'</strong>'.
                    '</a> <code>[qrcode_url id='.$post->ID.']</code> / <code>[qrcode_url name='.$post->post_name.']</code>'.
                '</p><p>'.
                    '<a href="'.$qrcodew_url.'" target="_blank">'.
                        '<strong>'.__('QRCode white',$this->prefix).'</strong>'.
                    '</a> <code>[qrcode_img &hellip; alt=white]</code> / <code>[qrcode_url &hellip; alt=white]</code>'.
                '</p><p>'.
                    '<a href="'.$qrcodeb_url.'" target="_blank">'.
                        '<strong>'.__('QRCode black',$this->prefix).'</strong>'.
                    '</a> <code>[qrcode_img &hellip; alt=black]</code> / <code>[qrcode_url &hellip; alt=black]</code>'.
                '</p>'.
                '<div class="clear"></div>';
        }
    }

    // validation function for "Output" metabox
    public function metabox_validate_attachments( $value ) {
        return $value;
    }

    // returns vcard data for custom post
    public function get_data( $post ) {
        // get fullname
        $fullname = get_post_meta( $post->ID, '_'.$this->prefix.'_fullname', true );
        // if fullname is empty, use post title
        if( empty($fullname) )
            $fullname = $post->post_title;
        // get email and phone values
        $data_email = (array) get_post_meta( $post->ID, '_'.$this->prefix.'_group_email', true );
        $data_phone = (array) get_post_meta( $post->ID, '_'.$this->prefix.'_group_phone', true );
        // validate email and phone values
        $group_email = array();
        $group_phone = array();
        foreach( array(
            'email' => array(&$data_email,&$group_email),
            'phone' => array(&$data_phone,&$group_phone),
        ) as $field => $group) {
            foreach( $group[0] as $values ) {
                // ensure defaults
                $values = wp_parse_args($values,array(
                    'type' => array(),
                    'value' => '',
                ));
                // only add non-empty values
                if( !empty($values['value']) )
                    array_push($group[1],$values);
            }
        }
        // get address values
        $data_addr = (array) get_post_meta( $post->ID, '_'.$this->prefix.'_group_addr',  true );
        // validate address values
        $group_addr = array();
        foreach( $data_addr as $addr ) {
            $addr_has_content = false;
            // check content of specific fields
            $addr_content_fields = array('country','postalcode','region','locality','street','extended','pobox');
            foreach( $addr_content_fields as $c ) {
                if( isset($addr[$c]) && !empty($addr[$c]) ) {
                    $addr_has_content = true;
                    break;
                }
            }
            // if specific fields have no content, exit
            if( !$addr_has_content )
                break;
            // ensure default fields
            $addr = wp_parse_args($addr,array(
                'type' => array(),
                'pobox' => '',
                'extended' => '',
                'street' => '',
                'locality' => '',
                'region' => '',
                'postalcode' => '',
                'country' => '',
                'label' => '',
            ));
            // add non-empty data sets
            array_push($group_addr,$addr);
        }
        // ensure vcard version setting
        $vcard_version = get_post_meta( $post->ID, '_'.$this->prefix.'_vcard_version',  true );
        $vcard_version = in_array($vcard_version,array('21','30')) ? $vcard_version : '21';
        // return all values
        return array(
            'fullname'       => $fullname,
            'vcard_version'  => $vcard_version,
            'givenname'      => get_post_meta( $post->ID, '_'.$this->prefix.'_givenname',      true ),
            'additionalname' => get_post_meta( $post->ID, '_'.$this->prefix.'_additionalname', true ),
            'familyname'     => get_post_meta( $post->ID, '_'.$this->prefix.'_familyname',     true ),
            'prefix'         => get_post_meta( $post->ID, '_'.$this->prefix.'_prefix',         true ),
            'suffix'         => get_post_meta( $post->ID, '_'.$this->prefix.'_suffix',         true ),
            'nick'           => get_post_meta( $post->ID, '_'.$this->prefix.'_nick',           true ),
            'photo'          => get_post_meta( $post->ID, '_'.$this->prefix.'_photo',          true ),
            'url'            => get_post_meta( $post->ID, '_'.$this->prefix.'_url',            true ),
            'pgpkey'         => get_post_meta( $post->ID, '_'.$this->prefix.'_pgpkey',         true ),
            'organization'   => get_post_meta( $post->ID, '_'.$this->prefix.'_organization',   true ),
            'title'          => get_post_meta( $post->ID, '_'.$this->prefix.'_title',          true ),
            'role'           => get_post_meta( $post->ID, '_'.$this->prefix.'_role',           true ),
            'logo'           => get_post_meta( $post->ID, '_'.$this->prefix.'_logo',           true ),
            'source'         => get_post_meta( $post->ID, '_'.$this->prefix.'_source',         true ),
            'group_email'    => $group_email,
            'group_phone'    => $group_phone,
            'group_addr'     => $group_addr,
        );
    }

    // generate hcard html
    public function get_hcard( $post, $use_address_tag=false ) {
        // retrieve vcard data
        extract( $this->get_data($post), EXTR_OVERWRITE );
        // begin html output
        $hcard = '<div class="vcard h-card">'."\n".
        // name / personal info
            ( empty($fullname)     ? '' : '<span class="fn p-name">'.$fullname.'</span>'."\n" ).
            '<span class="n">'."\n".
                ( empty($prefix)         ? '' : '<span class="honorific-prefix p-honorific-prefix">'.$prefix.'</span>'."\n" ).
                ( empty($givenname)      ? '' : '<span class="given-name p-given-name">'.$givenname.'</span>'."\n" ).
                ( empty($additionalname) ? '' : '<abbr class="additional-name p-additional-name">'.$additionalname.'</abbr>'."\n" ).
                ( empty($familyname)     ? '' : '<span class="family-name p-family-name">'.$familyname.'</span>'."\n" ).
                ( empty($suffix)         ? '' : '<span class="honorific-suffix p-honorific-suffix">'.$suffix.'</span>'."\n" ).
            '</span>'."\n".
            // additional personal info
            ( empty($nick)         ? '' : '<span class="nickname p-nickname">'.$nick.'</span>'."\n" ).
            ( empty($photo)        ? '' : '<img class="photo u-photo" src="'.$photo.'" alt="'.$fullname.'">'."\n" ).
            // organization / job info
            ( empty($organization) ? '' : '<span class="org p-org">'.$organization.'</span>'."\n" ).
            ( empty($title)        ? '' : '<span class="title p-job-title">'.$title.'</span>'."\n" ).
            ( empty($role)         ? '' : '<span class="role p-role">'.$role.'</span>'."\n" ).
            ( empty($logo)         ? '' : '<img class="logo u-logo" src="'.$logo.'" alt="'.$organization.'">'."\n" );
        // walk through address fields
        foreach( $group_addr as $i => $addr ) {
            // create address data fields as single variables
            extract( $addr, EXTR_OVERWRITE );
            // all type values to lowercase
            $types = array_map('strtolower',$type);
            // if multiple addresses and this is the first, add "pref"
            if( $i==0 && count($group_addr)>1 )
                array_push($types,'pref');
            // merge type classes
            $type = !empty($types) ? ' '.implode(' ',$types) : '';
            // define tag fpr address block
            $tag = $use_address_tag ? 'address' : 'div';
            // build address html
            $hcard .= '<'.$tag.' class="adr p-adr'.$type.'">'."\n".
                ( empty($pobox)      ? '' : '<span class="post-office-box p-post-office-box">'.$pobox.'</span>'."\n" ).
                ( empty($street)     ? '' : '<span class="street-address p-street-address">'.$street.'</span>'."\n" ).
                ( empty($extended)   ? '' : '<span class="extended-address p-extended-address">'.$extended.'</span>'."\n" ).
                ( empty($postalcode) ? '' : '<span class="postal-code p-postal-code">'.$postalcode.'</span>'."\n" ).
                ( empty($locality)   ? '' : '<span class="locality p-locality">'.$locality.'</span>'."\n" ).
                ( empty($region)     ? '' : '<span class="region p-region">'.$region.'</span>'."\n" ).
                ( empty($country)    ? '' : '<span class="country-name p-country-name">'.$country.'</span>'."\n" ).
                ( empty($label)      ? '' : '<span class="label p-label">'.nl2br($label).'</span>'."\n" ).
            '</'.$tag.'>'."\n";
        }
        // walk through emails and phones
        foreach( array('tel'=>$group_phone,'email'=>$group_email) as $field => $group ) {
            if( !empty($group) ) {
                foreach( $group as $i => $values ) {
                    // ensure type data
                    $types = isset($values['type']) ? $values['type'] : array();
                    // all types to lowercase
                    $types = array_map('strtolower',$types);
                    // if multiple items and this is first, add "pref"
                    if( $i==0 && count($group)>1 )
                        array_push($types,'pref');
                    $type = empty($types) ? '' : ' '.implode(' ',$types);
                    // build email html
                    $hcard .= '<a class="'.$field.' u-'.$field.$type.'" href="'.($field=='tel'?'tel':'mailto').':'.$values['value'].'">'.$values['value'].'</a>'."\n";
                }
            }
        }
        // build url html
        $hcard .= ( empty($url) ? '' : '<a class="url u-url" href="'.$url.'">'.$url.'</a>'."\n" ).
        // build pgpkey html
            ( empty($pgpkey) ? '' : '<a class="key u-key" href="'.$pgpkey.'">'.__('PGP Public Key',$this->prefix).'</a>'."\n" ).
        // end html output
            '</div>'."\n";
        // return html
        return $hcard;
    }

    // generate vcard content
    public function get_vcard( $post ) {
        // retrieve vcard data
        extract( $this->get_data($post), EXTR_OVERWRITE );
        // version differences
        switch( $vcard_version ) {
            case '30':
                $version = '3.0';
                $filterfunc = 'strtolower';
                $type_glue = ',';
                $type_pref = ';TYPE=';
                break;
            case '21':
            default:
                $version = '2.1';
                $filterfunc = 'strtoupper';
                $type_glue = ';';
                $type_pref = ';';
                break;
        }
        // begin vcard content
        $vcard = "BEGIN:VCARD\n".
        // using version 3.0, see http://www.rfcreader.com/#rfc2426
            "VERSION:$version\n".
        // name
            "N:$familyname;$givenname;$additionalname;$prefix;$suffix\n".
        // full name
            "FN:$fullname\n";
        // if a photo is set
        if( !empty($photo) ) {
            // try to find photo image type, use 'jpeg' as default
            $photo_type = strtolower( pathinfo( parse_url( $photo, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
            $photo_type = ( !$photo_type || in_array($photo_type,array('jpg','jpeg')) ) ? 'jpeg' : $photo_type;
            // photo
            $vcard .= 'PHOTO'.($vcard_version!='21'?';VALUE=url':'').$type_pref.$photo_type.':'.$photo."\n";
        }
        // nickname(s)
        $vcard .= ( empty($nick)   ? '' : "NICKNAME:$nick\n" ).
        // url
            ( empty($url)          ? '' : "URL:$url\n" ).
        // pgp key
            ( empty($pgpkey)       ? '' : 'KEY;'.($vcard_version!='21'?'TYPE=pgp':'PGP').":$pgpkey\n" ).
        // organization and job info
            ( empty($organization) ? '' : "ORG:$organization\n" ).
            ( empty($title)        ? '' : "TITLE:$title\n" ).
            ( empty($role)         ? '' : "ROLE:$role\n" ).
            ( empty($logo)         ? '' : 'LOGO'.($vcard_version!='21'?';VALUE=url':'').":$logo\n" );
        // walk through addresses
        if( !empty($group_addr) ) {
            foreach( $group_addr as $i => $addr ) {
                // create address data fields as single variables
                extract( $addr, EXTR_OVERWRITE );
                // if multiple addressess and this is the first, add "pref"
                if( $i==0 && count($group_addr)>1 )
                    array_push($types,'pref');
                // filter all type values
                $types = array_map($filterfunc,$type);
                // merge type values
                $type = !empty($types) ? implode($type_glue,$types) : '';
                // address
                $vcard .= "ADR$type_pref$type:$pobox;$extended;$street;$locality;$region;$postalcode;$country\n";
                // address label
                if( !empty($label) ) {
                    // repplace newlines with escaped version
                    $label = explode("\n",$label);
                    $label = array_map('trim',$label);
                    $vcard .= 'LABEL'.$type_pref.$type.':'.implode('\n',$label)."\n";
                }
            }
        }
        // walk through emails and phones
        foreach( array('TEL'=>$group_phone,'EMAIL'=>$group_email) as $field => $group ) {
            if( !empty($group) ) {
                foreach( $group as $i => $values ) {
                    // ensure type data
                    $types = isset($values['type']) ? $values['type'] : array();
                    // if multiple items and this is first, add "pref"
                    if( $i==0 && count($group)>1 )
                        array_push($types,'pref');
                    // filter all type values
                    $types = array_map($filterfunc,$types);
                    // add value to output
                    $vcard .= $field.$type_pref.implode($type_glue,$types).':'.$values['value']."\n";
                }
            }
        }
        // source
        $vcard .= "SOURCE:".( empty($source) ? $this->upload_url.'/'.$post->post_name.'.vcf' : $source )."\n";
        // revision date
        $rev = get_post_time('Y-m-d', true, $post, true);
        $vcard .= "REV:$rev\n".
        // end vcard content
            "END:VCARD";
        // return vcard content
        return $vcard;
    }

    // retrieve a vcard post by id or name
    private function get_post( $id_or_name ) {
        $post = null;
        $id = $id_or_name;
        // if id is not a number
        if( !is_int($id) ) {
            // get sanitized name
            $name = sanitize_key($id_or_name);
            // if not empty
            if( !empty($name) ) {
                // try to find a matching post
                global $wpdb;
                if( $post_id = $wpdb->get_var("SELECT ID FROM $wpdb->posts WHERE post_type='vcard' AND post_name='$name'") )
                    // set post id on success
                    $id = $post_id;
            }
        }
        // ensure id is a number
        $id = intval($id);
        // try to get post and return
        return get_post($id);
    }

    // shortcode, returns hcard html
    public function shortcode_hcard( $atts, $content=null ) {
        extract( shortcode_atts( array(
            'id' => null,
            'name' => null,
            'tag' => 'div'
        ), $atts ) );
        // try to get a post by name or id
        $post = $this->get_post($name?$name:$id);
        // return hcard url if post is present
        return $post ? $this->get_hcard( $post, strtolower($tag)=='address' ) : '';
    }

    // shortcode, returns vcard url
    public function shortcode_vcard_url( $atts, $content=null ) {
        extract( shortcode_atts( array(
            'id' => null,
            'name' => null
        ), $atts ) );
        // try to get a post by name or od
        $post = $this->get_post($name?$name:$id);
        // return vcard url if post is present
        return $post ? $this->upload_url.'/'.$post->post_name.'.vcf' : null;
    }

    // shortcode, returns vcard link
    public function shortcode_vcard_link( $atts, $content=null ) {
        extract( shortcode_atts( array(
            'id' => null,
            'name' => null
        ), $atts ) );
        // run shortcodes on content
        $content = do_shortcode($content);
        // try to get a post by name or id
        $post = $this->get_post($name?$name:$id);
        // exit if no post present
        if( !$post )
            return $content;
        // return html
        return '<a href="'.$this->upload_url.'/'.$post->post_name.'.vcf">'.$content.'</a>';
    }

    // shortcode, returns qrcode url
    public function shortcode_qrcode_url( $atts, $content=null ) {
        extract( shortcode_atts( array(
            'id' => null,
            'name' => null,
            'alt' => null
        ), $atts ) );
        $html = '';
        // get post by name or id
        if( $post = $this->get_post($name?$name:$id) ) {
            // alternative qrcode image (white or black)
            $alt = in_array($alt,array('white','black')) ? '_'.$alt : '';
            // text output
            $url = $this->upload_url.'/'.$post->post_name.$alt.'.png';
        }
        return $url;
    }

    // shortcode, returns qrcode img
    public function shortcode_qrcode_img( $atts, $content=null ) {
        extract( shortcode_atts( array(
            'id' => null,
            'name' => null,
            'alt' => null
        ), $atts ) );
        $html = '';
        // get post by name or id
        if( $post = $this->get_post($name?$name:$id) ) {
            // get vcard fullname for alt and title attributes
            $fullname = get_post_meta( $post->ID, '_'.$this->prefix.'_fullname', true );
            // if fullname is empty, use post title
            if( empty($fullname) )
                $fullname = $post->post_title;
            // alt/title attributes content
            $title = __('vCard',$this->prefix).' '.$fullname;
            // alternative qrcode image (white or black)
            $alt = in_array($alt,array('white','black')) ? '_'.$alt : '';
            // html output
            $html = '<img src="'.$this->upload_url.'/'.$post->post_name.$alt.'.png"'.
                ' alt="'.$title.'"'.
                ' title="'.$title.'">';
        }
        return $html;
    }

}
