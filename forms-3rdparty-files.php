<?php
/*

Plugin Name: Forms: 3rd-Party File Attachments
Plugin URI: https://github.com/zaus/forms-3rdparty-files
Description: Add file upload processing to Forms 3rdparty Integration
Author: zaus, dominiceales
Version: 0.4.1
Author URI: http://drzaus.com
Changelog:
	0.1 - initial idea from https://github.com/zaus/forms-3rdparty-integration/issues/40
	0.2 - working implementation for GF + CF7, file meta
	0.3 - refactored inheritance, 'better' form registration, include ninja forms
	0.4 - need to check for GF path (maybe different version than originally wrote against); return exception rather than throw it?
	0.4.1 - fix for GF validation 
*/

class F3i_Files_Plugin {
	const B = 'Forms3rdPartyIntegration';

	/**
	 * Setting for how to include the attachments:  path (unchanged), url, raw/binary, base64
	 */
	const OPTION_ATTACH_HOW = 'f3if_how';

	const VAL_ATTACH_PATH = 'path';
	const VAL_ATTACH_LINK = 'url';
	const VAL_ATTACH_RAW = 'raw';
	const VAL_ATTACH_BAS64 = 'base64';

	/**
	 * Where the files should show up in the submission array for mapping
	 */
	const SUBMISSION_ATTACH = '_FILES_';

	function __construct() {
		// expose files through submission $post array -- makes it available to mappings
		add_filter(self::B.'_get_submission', array(&$this, 'get_submission'), 11, 3);

		// configure whether to attach or not, how
		add_filter(self::B.'_service_settings', array(&$this, 'service_settings'), 10, 3);

		// register form plugins AFTER critical stuff ready
		add_action(self::B.'_init', array(__CLASS__, 'register'), 11, 1);

		$this->_file_entry = self::SUBMISSION_ATTACH; // or get from a configurable wp_option?
	}

	private $_file_entry; // alias to where we stick it in the submission/post array

	public function get_submission($submission, $form, $service) {
		// todo: apply shortcodes (or maybe just settings) to either:
		// 1. link to the file
		// 1-5. name of the file
		// 2. include the bytes
		// 3. base64 encode the bytes
		// 4. gzip the bytes
		// 6. shortcodes would allow combinations of the above

		// TODO: this really needs to be part of something from base F3i plugin; maybe inject formid (gf_xxx or cf_xxx) into submission?
		$plugin = is_object($form) ? get_class($form) : gettype($form);
		
		// given as array( form-input => server-path )
		$files = apply_filters(__CLASS__ . '_get_files', array(), $plugin, $form);

		### _log('files', $files);

		foreach($files as $k => $meta) {
			$submission[$k . '_attach'] = self::Transform($meta['path'], $service[self::OPTION_ATTACH_HOW]);
			$submission[$k . '_size'] = $meta['size'];
			$submission[$k . '_mime'] = $meta['mime'];
			// cf7 already has this; add for gf
			if((!isset($submission[$k]) || empty($submission[$k])) && isset($meta['name'])) $submission[$k] = $meta['name'];

			// just to make sure we have something
			if(empty($submission[$k])) $submission[$k] = basename($meta['path']);
		}

		return $submission;
	}

	/**
	 * Apply appropriate transformation of the value based on the requested 'how' method
	 * @param $value the original value
	 * @param $how how to transform it
	 * @return the converted value
	 */
	public static function Transform($value, $how) {
		switch($how) {
			case self::VAL_ATTACH_PATH: return $value;
			case self::VAL_ATTACH_LINK:
				// maybe strip wp_upload_dir()['basedir'] instead?
				return site_url( str_replace(ABSPATH, '', $value) );
			case self::VAL_ATTACH_RAW:
			case self::VAL_ATTACH_BAS64:
				try {
					$bytes = file_get_contents($value);
					// could throw an exception just to get the stack trace, but since we don't want to expose
					// all of that information return the 'message' instead
					if(false === $bytes) return array(
						'error' => "Couldn't read raw file as $how for Forms3rdpartyFiles plugin"
						// , 'at' => __FILE__ . ':' . __LINE__
					);
					if($how == self::VAL_ATTACH_BAS64) return base64_encode($bytes);
					return $bytes;
				} catch(Exception $ex) {
					return array(
						'error' => $ex->getMessage()
						//,'at' => $ex->getFile() . ':' . $ex->getLine()
					);
				}

		}
		// unknown
		return $value;
	}
	
	// must add (known) stuff after we're ready; any new hooks can attach themselves to `__CLASS__ . '_get_files'`
	public static function register() {
		if(is_plugin_active('contact-form-7/wp-contact-form-7.php') || class_exists('WPCF7_ContactForm') ) new F3i_CF7_Files;
		// TODO: is RGFormsModel deprecated?  according to http://stackoverflow.com/questions/26942558/rgformsmodel-questions-gravity-forms but it exists in code
		if(is_plugin_active('gravityforms/gravityforms.php') || class_exists('RGFormsModel') ) {
			F3i_Form_Files::as_gravity_form( new F3i_Form_Files('array') );
		}
		if(is_plugin_active('ninja-forms/ninja-forms.php') || class_exists('Ninja_Forms') ) new F3i_Form_Files('Ninja_Forms_Processing');
	}

	public function service_settings($eid, $P, $entity) {
	?>
		<fieldset><legend><span><?php _e('File Attachments', $P); ?></span></legend>
			<div class="inside">
				<em class="description">How to attach files to submission mappings.</em>

				<?php $field = self::OPTION_ATTACH_HOW; ?>
				<div class="field">
					<label for="<?php echo $field, '-', $eid ?>"><?php _e('Attachment style:', $P); ?></label>
					<select id="<?php echo $field, '-', $eid ?>" class="select" name="<?php echo $P, '[', $eid, '][', $field, ']'?>">
					<?php foreach(array(
						self::VAL_ATTACH_PATH => 'Server path (default)',
						self::VAL_ATTACH_LINK => 'URL Link',
						self::VAL_ATTACH_BAS64 => 'BASE64-encoded Bytes',
						self::VAL_ATTACH_RAW => 'Raw Bytes'
					) as $k => $v) { ?>
						<option value="<?php echo esc_attr($k) ; ?>" <?php selected($entity[$field], $k);?>><?php echo $v; ?></option>
					<?php } ?>
					</select>
					<em class="description"><?php echo sprintf(__('How to include file attachments.  They\'ll be available to mapping as %s.', $P), '<i><code>YOUR_SOURCE_FIELD</code></i><code>_attach</code>'); ?></em>
				</div>
			</div>
		</fieldset>
	<?php
	}//--	fn	service_settings
}//--	F3i_Files_Plugin

new F3i_Files_Plugin; // engage!

#region ----------- activate plugins appropriately -----------

/**
 * Base class for WP forms plugins to get their files appropriately
 */
abstract class F3i_Files_Form_Plugin {
	function __construct() {
		add_filter('F3i_Files_Plugin_get_files', array(&$this, 'get_files'), 10, 3);
	}

	abstract function get_files($files, $plugin, $form);
}

/**
 * A form plugin base class that expects to process the `$_FILES` variable
 * but must still check if it's an "expected plugin".  Used for Gravity Forms and probably Ninja Forms too.
 */
class F3i_Form_Files extends F3i_Files_Form_Plugin {
	var $plugin_expects;

	function __construct($plugin_expects) {
		parent::__construct();

		$this->plugin_expects = $plugin_expects;

		// because some versions of GF save to specific upload folder instead
		// hook 'gform_upload_path' happens too late to be of use
		// add_filter( 'gform_upload_path', array(&$this, 'get_gf_path'), 1, 2 );
	}

	static function as_gravity_form($instance) {
		add_filter(__CLASS__ . '_get_path', array(&$instance, 'gravity_form_path'), 10, 3);
	}
	function gravity_form_path($tmp, $field, $form) {
		$upload = GFFormsModel::get_temp_filename( $form['id'], $field );
		### _log(__FUNCTION__, $tmp, $field, $upload);

		// see `forms_model.php:3684` in function `move_temp_file`
		// overkill path.combine
		return '/' . implode('/', array(trim(GFFormsModel::get_upload_path( $form['id'] ), '/'), 'tmp', trim($upload['temp_filename'], '/')));
	}

	function get_files($files, $plugin, $form) {
		/*
		    [input_16] => Array
			(
				[name] => test-resume.docx
				[type] => application/octet-stream
				[tmp_name] => /var/tmp/phpl2U2Ha
				[error] => 0
				[size] => 28
			)
		*/
		
		// TODO: figure out proper plugin registration so only appropriate one fires
		if($plugin != $this->plugin_expects) return $files;

		return $this->attach_files($files, $form);
	}

	protected function attach_files($files, $form) {
		/*
		### _log('attach_gf_files', $files, $_FILES
			//, $form
			, GFFormsModel::$uploaded_files
			, GFFormsModel::get_upload_path( $form['id'] )
		);
		*/

		foreach($_FILES as $field => $data) {
			$meta = array();
			
			$meta['path'] = apply_filters(__CLASS__ . '_get_path', $data['tmp_name'], $field, $form);
			// but GF doesn't provide the filename? toss on other stuff for fun while we're at it
			$meta['name'] = $data['name'];
			$finfo = new finfo(FILEINFO_MIME_TYPE);
			$meta['mime'] = $finfo->file($meta['path']);
			$meta['size'] = $data['size'];
			
			$files[$field] = $meta;
		}

		### _log(__FUNCTION__, $files);
		
		return $files;
	}
}


class F3i_CF7_Files extends F3i_Files_Form_Plugin {
	function get_files($files, $plugin, $form) {
		// TODO: figure out proper plugin registration so only appropriate one fires
		if($plugin != 'WPCF7_ContactForm') return $files;

		$cf7 = WPCF7_Submission::get_instance();
		if(!$cf7) return array();
		
		$files = $cf7->uploaded_files();
		
		### _log('cf7-submission', $plugin, $files);

		// attach metadata while we're at it
		foreach($files as $field => &$meta) {
			$meta = array('path' => $meta);
			
			$finfo = new finfo(FILEINFO_MIME_TYPE);
			$meta['mime'] = $finfo->file($meta['path']);
			$meta['size'] = filesize($meta['path']);
		}
		return $files;
	}
}


#endregion ----------- activate plugins appropriately -----------
