<?php
/*
Plugin Name: GP Bulk Download Translations
Plugin URI: http://www.weixiaoduo.com/gp-bulk-download-translations
Description: Download all the translation sets of a GlotPress project in a zip file at once.
Version: 2.2
Author: Weixiaoduo.com
Author URI: https://www.weixiaoduo.com
Tags: glotpress, glotpress plugin
License: GPLv2
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

class GP_Bulk_Download_Translations {
	public $id = 'gp-bulk-download-translations';
	public $api;
	public $last_method_called;
	public $class_name;
	public $request_running;

	public function __construct() {
		// We need the Zip class to do the bulk export, if it doesn't exist, don't bother enabling the plugin.
		if( ! class_exists( 'ZipArchive' ) ) {
			return;
		}

		add_action( 'gp_project_actions', array( $this, 'gp_project_actions' ), 10, 2 );

		// We can't use the filter in the defaults route code because plugins don't load until after
		// it has already run, so instead add the routes directly to the global GP_Router object.
		GP::$router->add( "/bulk-export/(.+?)", array( $this, 'bulk_export' ), 'get' );
		GP::$router->add( "/bulk-export/(.+?)", array( $this, 'bulk_export' ), 'post' );
		GP::$router->add( "/bulk-export-multi", array( $this, 'bulk_export_multi' ), 'get' );

	}

	public function gp_project_actions( $actions, $project ) {
		$actions[] .= gp_link_get( gp_url( 'bulk-export/' . $project->slug . '?format=zip'), __('批量导出译文集') );

		return $actions;
	}

	public function before_request() {
	}

	public function bulk_export( $project_path ) {
		// The project path is url encoded, so decode before we do anything with it.
		$project_path = urldecode( $project_path );

		// Determine our temporary directory.
		$temp_dir = gp_const_get('GP_BULK_DOWNLOAD_TRANSLATIONS_TEMP_DIR', sys_get_temp_dir());

		// Get a temporary file, use bdt as the first three letters of it.
		$temp_dir = tempnam( $temp_dir, 'bdt' );

		// Now delete the file and recreate it as a directory.
		unlink( $temp_dir );
		mkdir( $temp_dir );

		// Create a project class to use to get the project object.
		$project_class = new GP_Project;

		// Get the project object from the project path that was passed in.
		$project_obj = $project_class->by_path( $project_path );

		// Export the files to the temporary directory.
		$files = $this->generate_export_files( $project_obj, $temp_dir );

		// Setup the zip file name to use, it's the project name + .zip.
		$zip_file = $temp_dir . '/' . $project_path . '.zip';

		// Create the new archive.
		$zip = new ZipArchive;

		if ( $zip->open( $zip_file, ZipArchive::CREATE ) === TRUE ) {
			// Loop through all of the files we created and add them to the zip file.
			foreach( $files as $file ) {
				// The first parameter is the full path to the local file, the second is the name as it will appear in the zip file.
				// Note this does not actually write data to the zip file.
				$zip->addFile( $temp_dir . '/' . $file, $file );
			}

			// Close the zip file, this does the actual writing of the data.
			$zip->close();
		}

		// Since we can't delete the export files until after we close the zip, loop through the files once more
		// and delete them.
		foreach( $files as $file ) {
			unlink( $temp_dir . '/' . $file );
		}

		// Generate download filename with locale
		$locale = function_exists('get_locale') ? get_locale() : (defined('WPLANG') && !empty(WPLANG) ? WPLANG : 'en_US');
		$download_filename = str_replace('/', '-', $project_path) . '-' . $locale . '.zip';
		
		// Generate our headers for the file download.
		header( 'Content-Description: File Transfer' );
		header( 'Pragma: public' );
		header( 'Expires: 0' );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s' ) . ' GMT' );
		header( 'Cache-Control: must-revalidate, post-check=0, pre-check=0' );
		header( 'Content-Disposition: attachment; filename=' . $download_filename );
		header( 'Content-Type: application/octet-stream' );
		header( 'Connection: close' );

		// Write the zip file out to the client.
		readfile( $zip_file );

		// Delete the zip file.
		unlink( $zip_file );

		// Remove the temporary directory and we're done!
		rmdir( $temp_dir );
	}

	public function generate_export_files( $project, $path ) {
		// Loop through the supported format options and determine which ones we're exporting.
		$include_formats = array();
		foreach ( GP::$formats as $slug => $format ) {
			if( gp_const_get( 'GP_BULK_DOWNLOAD_TRANSLATIONS_FORMAT_' . strtoupper( str_replace( '.', '-', $slug ) ), false ) == true ) {
				$include_formats[] = $slug;
			}
		}

		// If we didn't have any formats set for export, use PO files by default.
		if( count( $include_formats ) == 0 ) { $include_formats = array( 'po' ); }

		// Get the translations sets from the project ID.
		$translation_sets = GP::$translation_set->by_project_id( $project->id );

		// Setup an array to use to track the file names we're creating.
		$files = array();

		// Loop through all the sets.
		foreach( $translation_sets as $set ) {
			// Loop through all the formats we're exporting
			foreach( $include_formats as $format ) {
				// Export the PO file for this translation set.
				$files[] .= $this->_export_to_file( $format, $path, $project, $set->locale, $set );
			}
		}

		return $files;
	}

	private function _export_to_file( $format, $dir, $project, $locale, $set ) {
		// Get the entries we going to export.
		$entries = GP::$translation->for_export( $project, $set );

		// Get the slug for this locale.
		$locale_slug = $set->locale;

		// Get the locale object by the slug.
		$locale = GP_Locales::by_slug( $locale_slug );

		// Apply any filters that other plugins may have implemented.
		$export_locale = apply_filters( 'gp_export_locale', $locale->slug, $locale );

		// Get the format object to create the export with.
		$format_obj = gp_array_get( GP::$formats, $format, null );

		// Create the default file name.
		$filename = sprintf( '%s-%s.'.$format_obj->extension, str_replace( '/', '-', $project->path ), $export_locale );

		// Apply any filters that other plugins may have implemented to the filename.
		$filename = apply_filters( 'gp_export_translations_filename', $filename, $format_obj, $locale, $project, $set );

		// Get the contents from the formatter.
		$contents = $format_obj->print_exported_file( $project, $locale, $set, $entries );

		// Write the contents out to the file.
		$fh = fopen( $dir . '/' . $filename, 'w' );
		fwrite( $fh, $contents );
		fclose( $fh );

		// Return the filename for future reference.
		return $filename;
	}


	public function bulk_export_multi() {
		// Clean any output buffers
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		
		// Get projects list
		$projects_param = isset($_GET['projects']) ? sanitize_text_field($_GET['projects']) : '';
		if (empty($projects_param)) {
			wp_die('请提供要导出的项目列表。使用格式: ?projects=project1,project2,project3');
		}
		
		// Check if flat structure is requested (no subfolders)
		$use_flat = isset($_GET['flat']) && $_GET['flat'] === '1';
		
		// Parse project paths
		$project_paths = array_map('trim', explode(',', $projects_param));
		$project_paths = array_filter($project_paths);
		
		if (empty($project_paths)) {
			wp_die('项目列表为空');
		}
		
		// Create temp directory
		$temp_dir = gp_const_get('GP_BULK_DOWNLOAD_TRANSLATIONS_TEMP_DIR', sys_get_temp_dir());
		$temp_dir = tempnam($temp_dir, 'bdtm');
		unlink($temp_dir);
		mkdir($temp_dir);
		
		$all_files = array();
		$project_class = new GP_Project;
		$first_project_slug = null;
		
		// Process each project
		foreach ($project_paths as $project_path) {
			$project_path = urldecode($project_path);
			$project_obj = $project_class->by_path($project_path);
			
			if (!$project_obj) {
				error_log("Project not found: $project_path");
				continue;
			}
			
			// Remember first project for filename
			if ($first_project_slug === null) {
				$first_project_slug = $project_obj->slug;
			}
			
			// Determine export directory based on flat parameter
			if ($use_flat) {
				// Flat structure: all files in root
				$export_dir = $temp_dir;
			} else {
				// Hierarchical structure: each project in subdirectory
				$export_dir = $temp_dir . '/' . sanitize_file_name($project_path);
				mkdir($export_dir, 0755, true);
			}
			
			// Export translations for this project
			$files = $this->generate_export_files($project_obj, $export_dir);
			
			// Record file paths
			foreach ($files as $file) {
				if ($use_flat) {
					$all_files[] = $file;
				} else {
					$all_files[] = sanitize_file_name($project_path) . '/' . $file;
				}
			}
		}
		
		if (empty($all_files)) {
			rmdir($temp_dir);
			wp_die('没有找到可导出的翻译文件');
		}
		
		// Generate ZIP filename: first-project-package-locale.zip
		$site_locale = function_exists('get_locale') ? get_locale() : (defined('WPLANG') && !empty(WPLANG) ? WPLANG : 'en_US');
		$zip_filename = ($first_project_slug ? $first_project_slug : 'translations') . '-package-' . $site_locale . '.zip';
		$zip_file = $temp_dir . '/' . $zip_filename;
		
		// Create ZIP file
		$zip = new ZipArchive;
		
		if ($zip->open($zip_file, ZipArchive::CREATE) === TRUE) {
			foreach ($all_files as $file) {
				$full_path = $temp_dir . '/' . $file;
				if (file_exists($full_path)) {
					$zip->addFile($full_path, $file);
				}
			}
			$zip->close();
		}
		
		// Clean up temporary files
		foreach ($all_files as $file) {
			$full_path = $temp_dir . '/' . $file;
			if (file_exists($full_path)) {
				unlink($full_path);
			}
		}
		
		// Remove project subdirectories (if not flat)
		if (!$use_flat) {
			foreach ($project_paths as $project_path) {
				$project_dir = $temp_dir . '/' . sanitize_file_name($project_path);
				if (is_dir($project_dir)) {
					rmdir($project_dir);
				}
			}
		}
		
		// Output ZIP file
		$file_size = filesize($zip_file);
		
		header('Content-Description: File Transfer');
		header('Content-Type: application/zip');
		header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
		header('Content-Transfer-Encoding: binary');
		header('Content-Length: ' . $file_size);
		header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
		header('Pragma: public');
		header('Expires: 0');
		
		if (ob_get_level()) {
			ob_end_clean();
		}
		
		readfile($zip_file);
		
		unlink($zip_file);
		rmdir($temp_dir);
		
		exit;
	}

	public function after_request() {
	}

}

// Add an action to WordPress's init hook to setup the plugin.  Don't just setup the plugin here as the GlotPress plugin may not have loaded yet.
// Load admin page
if ( is_admin() ) {
	require_once plugin_dir_path( __FILE__ ) . 'admin-page.php';
}
add_action( 'gp_init', 'gp_bulk_download_translations_init' );

// This function creates the plugin.
function gp_bulk_download_translations_init() {
	GLOBAL $gp_bulk_download_translations;

	$gp_bulk_download_translations = new GP_Bulk_Download_Translations;
}
