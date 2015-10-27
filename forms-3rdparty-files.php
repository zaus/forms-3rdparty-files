<?php
/*

Plugin Name: Forms: 3rd-Party File Attachments
Plugin URI: https://github.com/zaus/forms-3rdparty-files
Description: Add file upload processing to Forms 3rdparty Integration
Author: zaus, dominiceales
Version: 0.1
Author URI: http://drzaus.com
Changelog:
	0.1 - initial idea from https://github.com/zaus/forms-3rdparty-integration/issues/40

*/

abstract class F3i_Files_Base {
	const B = 'Forms3rdPartyIntegration';

	/**
	 * Setting for how to include the attachments:  path (unchanged), url, raw/binary, base64
	 */
	const OPTION_ATTACH_HOW = 'f3if_how';
	/**
	 * Setting for what index to include the attachments
	 */
	const OPTION_ATTACH_KEY = 'f3if_key';

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

		$files = $this->get_files();

		### _log('files', $files);

		$overwrite = empty($service[self::OPTION_ATTACH_KEY]) || ! $service[self::OPTION_ATTACH_KEY];
		foreach($files as $k => $v) {
			$submission[$overwrite ? $k : $k . '_attach'] = self::Transform($v, $service[self::OPTION_ATTACH_HOW]);
		}

		return $submission;
	}

	abstract protected function get_files();

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
		if(is_plugin_active('gravityforms/gravityforms.php') || class_exists('RGFormsModel') ) new F3i_GF_Files;
		if(is_plugin_active('contact-form-7/wp-contact-form-7.php') || class_exists('WPCF7_ContactForm') ) new F3i_CF7_Files;
		//if(is_plugin_active('ninja-forms/ninja-forms.php') || class_exists('Ninja_Forms') ) new F3i_Ninja_Files;

		do_action(__CLASS__ . '_register'); // extend
	}

	public function service_settings($eid, $P, $entity) {
	?>
		<fieldset><legend><span><?php _e('File Attachments', $P); ?></span></legend>
			<div class="inside">
				<em class="description">How to attach files to submission mappings.</em>

				<?php $field = self::OPTION_ATTACH_KEY; ?>
				<div class="field">
					<label for="<?php echo $field, '-', $eid ?>"><?php _e('Retain original input:', $P); ?></label>
					<input id="<?php echo $field, '-', $eid ?>" type="checkbox" class="checkbox" name="<?php echo $P, '[', $eid, '][', $field, ']'?>" value="1" <?php isset($entity[$field]) && checked($entity[$field], 1) ?> />
					<em class="description"><?php echo sprintf( __('Should the file attachment be attached with the same key as the input (unchecked), or with fieldname %s to retain the original (checked)?'), '<code>originalkey_attach</code>') ?></em>
				</div>
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
	protected function get_files() {
		return $_FILES; // is this the same array/key format?
	}
}


class F3i_CF7_Files extends F3i_Files_Base {
	protected function get_files() {
		$cf7 = WPCF7_Submission::get_instance();
		return $cf7 ? $cf7->uploaded_files() : array();
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
