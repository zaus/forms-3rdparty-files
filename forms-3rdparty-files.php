<?php
/*

Plugin Name: Forms: 3rd-Party File Attachments
Plugin URI: https://github.com/zaus/forms-3rdparty-files
Description: Add file upload processing to Forms 3rdparty Integration
Author: zaus, dominiceales
Version: 0.2
Author URI: http://drzaus.com
Changelog:
	0.1 - initial idea from https://github.com/zaus/forms-3rdparty-integration/issues/40
	0.2 - working implementation for GF + CF7, file meta

*/

abstract class F3i_Files_Base {
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

	static $_once = false;

	function __construct() {
		// expose files through submission $post array -- makes it available to mappings
		add_filter(self::B.'_get_submission', array(&$this, 'get_submission'), 11, 3);

		// configure whether to attach or not, how; because this is a base class only do it once
		if(!self::$_once) {
			add_filter(self::B.'_service_settings', array(&$this, 'service_settings'), 10, 3);
			self::$_once = true;
		}

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
		$files = $this->get_files($plugin);

		### _log('files', $files);

		foreach($files as $k => $meta) {
			$submission[$k . '_attach'] = self::Transform($meta['path'], $service[self::OPTION_ATTACH_HOW]);
			$submission[$k . '_size'] = $meta['size'];
			$submission[$k . '_mime'] = $meta['mime'];
			// cf7 already has this; add for gf
			if(isset($meta['name'])) $submission[$k] = $meta['name'];
		}

		return $submission;
	}

	abstract protected function get_files($plugin);

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
				$bytes = file_get_contents($value);
				if(false === $bytes) throw new Exception('Couldn\'t read raw file for Forms3rdpartyFiles plugin');
				return $bytes;
			case self::VAL_ATTACH_BAS64:
				$bytes = file_get_contents($value);
				if(false === $bytes) throw new Exception('Couldn\'t read raw file to base64 for Forms3rdpartyFiles plugin');
				return base64_encode($bytes);
		}
		// unknown
		return $value;
	}
	
	public static function init() {
		add_action(self::B.'_init', array(__CLASS__, 'register'), 11, 1);
	}
	
	// must add stuff after we're ready

	public static function register() {
		if(is_plugin_active('contact-form-7/wp-contact-form-7.php') || class_exists('WPCF7_ContactForm') ) new F3i_CF7_Files;
		if(is_plugin_active('gravityforms/gravityforms.php') || class_exists('RGFormsModel') ) new F3i_GF_Files;
		//if(is_plugin_active('ninja-forms/ninja-forms.php') || class_exists('Ninja_Forms') ) new F3i_Ninja_Files;

		do_action(__CLASS__ . '_register'); // extend
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
					<em class="description"><?php _e('How to include file attachments.', $P); ?></em>
				</div>
			</div>
		</fieldset>
	<?php
	}//--	fn	service_settings
}//--	F3i_Files_Base
F3i_Files_Base::init();

#region ----------- activate plugins appropriately -----------

class F3i_GF_Files extends F3i_Files_Base {
	protected function get_files($plugin) {
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
		if($plugin != 'array') return array();
		
		### _log('gf-submission', $plugin, $_FILES);
		
		$files = array();
		foreach($_FILES as $field => $data) {
			$meta = array();
			
			$meta['path'] = $data['tmp_name'];
			// but GF doesn't provide the filename? toss on other stuff for fun while we're at it
			$meta['name'] = $data['name'];
			$finfo = new finfo(FILEINFO_MIME_TYPE);
			$meta['mime'] = $finfo->file($data['tmp_name']);
			$meta['size'] = $data['size'];
			
			$files[$field] = $meta;
		}
		
		return $files;
	}
}


class F3i_CF7_Files extends F3i_Files_Base {
	protected function get_files($plugin) {
		// TODO: figure out proper plugin registration so only appropriate one fires
		if($plugin != 'WPCF7_ContactForm') return array();

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

// not sure if this is necessary?
/*
class F3i_Ninja_Files extends F3i_Files_Base {
	protected function get_files() {
		return $_FILES;
	}
}
*/

#endregion ----------- activate plugins appropriately -----------
