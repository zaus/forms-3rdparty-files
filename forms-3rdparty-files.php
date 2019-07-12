<?php
/*

Plugin Name: Forms: 3rd-Party File Attachments
Plugin URI: https://github.com/zaus/forms-3rdparty-files
Description: Add file upload processing to Forms 3rdparty Integration
Author: zaus, dominiceales
Version: 0.5.2
Author URI: http://drzaus.com
Changelog:
	0.1 - initial idea from https://github.com/zaus/forms-3rdparty-integration/issues/40
	0.2 - working implementation for GF + CF7, file meta
	0.3 - refactored inheritance, 'better' form registration, include ninja forms
	0.4 - need to check for GF path (maybe different version than originally wrote against); return exception rather than throw it?
	0.4.1 - fix for GF validation 
	0.5 - refactored support for GF single and multifile fields
	0.5.2 - special GF attach by label instead; refactored interval keys, remember last service
*/

class F3i_Files_Plugin {
	const B = 'Forms3rdPartyIntegration';

	/**
	 * Setting for how to include the attachments:  path (unchanged), url, raw/binary, base64
	 */
	const OPTION_ATTACH_HOW = 'f3if_how';
	/**
	 * Style for referencing GF fields
	 */
	const OPTION_GF_STYLE = 'f3if_gf';

	const VAL_ATTACH_PATH = 'path';
	const VAL_ATTACH_LINK = 'url';
	const VAL_ATTACH_RAW = 'raw';
	const VAL_ATTACH_BAS64 = 'base64';

	/**
	 * Where the files should show up in the submission array for mapping
	 */
	const SUBMISSION_ATTACH = '_FILES_';

	public static $last_service;

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

		// save rather than pass so other hooks can access settings
		self::$last_service = $service;

		// TODO: this really needs to be part of something from base F3i plugin; maybe inject formid (gf_xxx or cf_xxx) into submission?
		$plugin = is_object($form) ? get_class($form) : gettype($form);
		
		// given as array( form-input => server-path )
		$files = apply_filters(__CLASS__ . '_get_files', array(), $plugin, $form);

		###_log('files after filter', $files);

		foreach($files as $k => $meta) {
			$submission[$k . '_attach'] = self::Transform($meta, $service[self::OPTION_ATTACH_HOW]);
			$submission[$k . '_size'] = $meta['size'];
			$submission[$k . '_mime'] = $meta['mime'];
			// cf7 already has this; add for gf
			if((!isset($submission[$k]) || empty($submission[$k])) && isset($meta['name'])) $submission[$k] = $meta['name'];

			// just to make sure we have something
			if(empty($submission[$k])) $submission[$k] = basename($meta[F3i_Files_Form_Plugin::FINAL_META_KEY]);
		}

		return $submission;
	}

	/**
	 * Apply appropriate transformation of the value based on the requested 'how' method
	 * @param $meta the original metadata, including value
	 * @param $how how to transform it
	 * @return the converted value
	 */
	public static function Transform($meta, $how) {
		switch($how) {
			case self::VAL_ATTACH_PATH: return $meta[F3i_Files_Form_Plugin::FINAL_META_KEY];
			case self::VAL_ATTACH_LINK:
				// maybe strip wp_upload_dir()['basedir'] instead?
				return site_url( str_replace(ABSPATH, '', $meta[F3i_Files_Form_Plugin::FINAL_META_KEY]) );
			case self::VAL_ATTACH_RAW:
			case self::VAL_ATTACH_BAS64:
				try {
					$bytes = file_get_contents($meta[F3i_Files_Form_Plugin::FINAL_META_KEY]);
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
		return $meta;
	}
	
	// must add (known) stuff after we're ready; any new hooks can attach themselves to `__CLASS__ . '_get_files'`
	public static function register() {
		if(self::has_cf7()) new F3i_CF7_Files;
		// TODO: is RGFormsModel deprecated?  according to http://stackoverflow.com/questions/26942558/rgformsmodel-questions-gravity-forms but it exists in code
		if(self::has_gf()) F3i_Form_Files::as_gravity_form( new F3i_Form_Files('array') );
		if(self::has_ninja()) new F3i_Form_Files('Ninja_Forms_Processing');
	}

	public static function has_cf7() {
		return is_plugin_active('contact-form-7/wp-contact-form-7.php') || class_exists('WPCF7_ContactForm');
	}
	public static function has_gf() {
		return is_plugin_active('gravityforms/gravityforms.php') || class_exists('RGFormsModel');
	}
	public static function has_ninja() {
		return is_plugin_active('ninja-forms/ninja-forms.php') || class_exists('Ninja_Forms');
	}

	public function service_settings($eid, $P, $entity) {
	?>
		<fieldset class="postbox"><legend class="hndle"><span><?php _e('File Attachments', $P); ?></span></legend>
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
						<option value="<?php echo esc_attr($k) ; ?>" <?php selected(isset($entity[$field]) ? $entity[$field] : null, $k);?>><?php echo $v; ?></option>
					<?php } ?>
					</select>
					<em class="description"><?php echo sprintf(__('How to include file attachments.  They\'ll be available to mapping as %s.', $P), '<i><code>YOUR_SOURCE_FIELD</code></i><code>_attach</code>'); ?></em>
				</div>

				<?php
				if(self::has_gf()) :
				$field = self::OPTION_GF_STYLE; ?>
				<div class="field">
					<label for="<?php echo $field, '-', $eid ?>"><?php _e('Gravity Forms index style:', $P); ?></label>
					<?php foreach(array('id'=>'ID', 'lbl' => 'Label') as $k => $label) : ?>
						<label for="<?php echo $field, '-', $eid, $k ?>"><?php _e($label, $P); ?></label>
						<input type="radio" id="<?php echo $field, '-', $eid, $k ?>" class="radio" name="<?php echo $P, '[', $eid, '][', $field, ']'?>" value="<?php echo $k ?>" <?php checked(isset($entity[$field]) ? $entity[$field] : '', $k) ?>/>
					<?php endforeach; ?>
					<em class="description"><?php echo sprintf(__('How to reference fields in Gravity Forms, either as %s for ID or "%s" for Label.', $P), '<i><code>input_4</code></i>', 'My Field Label'); ?></em>
				</div>
				<?php
				endif;
				?>
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
	/**
	 * meta key of where the current (temp) file lives
	 */
	const CURRENT_META_KEY = 'curr';
	/**
	 * meta key of where the final (permanent) file lives
	 */
	const FINAL_META_KEY = 'final';

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

	/**
	 * Attach gravity-forms specific hooks
	 */
	public static function as_gravity_form($instance) {
		add_filter(__CLASS__ . '_temp_path', array(&$instance, 'gravity_forms_temp_path'), 10, 2);
		add_filter(__CLASS__ . '_actual_path', array(&$instance, 'gravity_forms_actual_path'), 10, 2);
		add_filter(__CLASS__ . '_list_files', array(&$instance, 'gravity_forms_list_files'), 10, 3);
	}
	/**
	 * Attach gravity-forms specific hooks
	 */
	public static function as_plain_files($instance) {
		add_filter(__CLASS__ . '_temp_path', array(&$instance, 'plain_files_temp_path'), 10, 2);
		add_filter(__CLASS__ . '_actual_path', array(&$instance, 'plain_files_actual_path'), 10, 2);
		add_filter(__CLASS__ . '_list_files', array(&$instance, 'plain_files_list_files'), 10, 2);
	}

	/**
	 * Get the full temporary path for the given form id and temp name
	 */
	public function gravity_forms_temp_path($fid, $tmp) {
		return GFFormsModel::get_upload_path( $fid ) . '/tmp/' . $tmp;
	}
	/**
	 * Get the actual path for the given form id and file name
	 */
	public function gravity_forms_actual_path($fid, $name) {
		// see `forms_model.php` in function `move_temp_file` --
		$meta = GFFormsModel::get_file_upload_path( $fid, $name ); // gives url=>xxx, path=>xxx
				// ::get_upload_path only gives the base folder; `tmp` is the intermediate subfolder

		return $meta['path']; // we'll reconstruct the url later via option
	}
	/**
	 * Get all single and multiple file upload data, but standardize them both to same format
	 */
	public function gravity_forms_list_files($result, $fid, $form) {
		// because of where we've hooked, we should have access to the raw files for single-upload fields
		$singles = $_FILES;

		$multi = GFFormsModel::$uploaded_files[$fid];

		$use_label = !isset(F3i_Files_Plugin::$last_service[F3i_Files_Plugin::OPTION_GF_STYLE]) || F3i_Files_Plugin::$last_service[F3i_Files_Plugin::OPTION_GF_STYLE] != 'id';

		###_log(__FUNCTION__, $use_label ? 'use label' : 'use id', $form);

		###_log('comparing changed result before', $multi);

		if($use_label) {
			foreach($multi as $field => $data) {
				if(!is_array($data)) continue; // because singles also appear in this list
				$lbl = $this->extract_field_label($field, $form['fields'], $data);
				$multi[$lbl] = $data;
				unset($multi[$field]);
			}
		}
		###_log('comparing changed result after', $multi);

		$result += $multi;
		###_log(__FUNCTION__ . ' multi result', $result);

		// merge singles if present
		if(!isset($singles) || empty($singles)) return $result;

		foreach($singles as $field => $data) {
			// reformat data to match uploaded_files
			$data['uploaded_filename'] = $data['name'];
			if(isset($data['type'])) $data['mime'] = $data['type'];
			// note that the php temp file `$data['tmp_name']` has already disappeared, so must get from GF
			$upload = GFFormsModel::get_temp_filename( $fid, $field );
			if(!empty($upload))
				$data['temp_filename'] = $this->gravity_forms_temp_path($fid, trim($upload['temp_filename'], '/'));

			// extract field index to get name if desired
			if($use_label) $field = $this->extract_field_label($field, $form['fields'], $data);

			$result[$field] = array($data);
		}

		###_log(__FUNCTION__ . ' with singles result', $result);
		return $result;
	}
	private function extract_field_label($name, $fields, $data) {
		$id = intval(substr($name, 6));
		###_log(__FUNCTION__, $name, $id, $fields);
		// save this in case we need it later?
		// $data['field_id'] = $id;

		// must scan the fields array for the matching id
		foreach($fields as $i => $field) {
			###_log('comparing', $i, $id, $field->id, $field->label, $field->adminLabel);

			if($id == $field->id)
				return !isset($field->adminLabel) || empty($field->adminLabel) ? $field->label : $field->adminLabel;
		}

		// couldn't find it so return original
		return $name;

	}

	/**
	 * Get the full temporary path for the given form id and temp name
	 */
	public function plain_files_temp_path($fid, $tmp) {
		return $tmp;
	}
	/**
	 * Get the actual path for the given form id and file name
	 */
	public function plain_files_actual_path($fid, $name) {
		return $name; // todo
	}
	/**
	 * Get all single and multiple file upload data, but standardize them both to same format
	 */
	public function plain_files_list_files($result, $fid) {
		// todo
		
		// because of where we've hooked, we should have access to the raw files for single-upload fields
		$singles = $_FILES;

		// merge singles if present
		if(!isset($singles) || empty($singles)) return $result;

		foreach($singles as $field => $data) {
			// reformat data to match uploaded_files
			$data['uploaded_filename'] = $data['name'];
			if(isset($data['type'])) $data['mime'] = $data['type'];
			$data['temp_filename'] = $data['tmp_name'];

			$result[$field] = array($data);
		}

		return $result;
	}

	private function parse_attachment(&$data, $fid) {
		$meta = array(); // ready the result holder

		$meta['name'] = $data['uploaded_filename'];

		// using the uploaded name, get the new upload path (which will autonumber for existing)
		// but the current file lives in the temporary spot, maybe explicitly given or we have to find it from gf
		$current = $data['temp_filename'];
		if(!file_exists($current)) $current = apply_filters(__CLASS__ . '_temp_path', $fid, $current);
		
		// the file will *eventually* live here
		$final = apply_filters(__CLASS__ . '_actual_path', $fid, $meta['name'] );

		// but where does the file actually live right now?
		if(!file_exists($current)) $current = $final;

		// save both locations so we can get the actual bytes later too
		$meta[self::CURRENT_META_KEY] = $current;
		$meta[self::FINAL_META_KEY] = $final;

		// other info we can get as long as we are here, if we don't have it already
		if(isset($data['mime'])) $meta['mime'] = $data['mime'];
		else {
			$finfo = new finfo(FILEINFO_MIME_TYPE);
			$meta['mime'] = $finfo->file($current);
		}
		$meta['size'] = isset($data['size']) ? $data['size'] : filesize($current);

		return $meta;
	}

	protected function attach_files($files, $form) {
		// _log('attach_gf_files', $files, $_FILES ###
		// 	//, $form
		// 	, GFFormsModel::$uploaded_files
		// 	, GFFormsModel::get_upload_path( $form['id'] )
		// );
		
		$listed = apply_filters(__CLASS__ . '_list_files', array(), $form['id'], $form);
		###_log(__FUNCTION__, 'before', $listed);
		
		foreach($listed as $field => $fieldFiles)
		foreach((array)$fieldFiles as $i => $data) {
			$meta = $this->parse_attachment($data, $form['id']);

			###_log(__FUNCTION__ . '/loop', $field, $i, $data, $meta); ###
						
			// add the first multifile just like the old/single style
			$files[$i < 1 ? $field : $field . '.' . (1+$i)] = $meta;
		}
		
		###_log(__FUNCTION__, 'after', $files);
		
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
			$meta = array(
				self::FINAL_META_KEY => $meta,
				// self::CURRENT_META_KEY => $meta,
			);
			
			$finfo = new finfo(FILEINFO_MIME_TYPE);
			$meta['mime'] = $finfo->file($meta[self::FINAL_META_KEY]);
			$meta['size'] = filesize($meta[self::FINAL_META_KEY]);
		}
		return $files;
	}
}


#endregion ----------- activate plugins appropriately -----------
