<?php

error_reporting(E_ALL);

/**
 * Class to download test content from specified source, extract, put extracted data into PHP array and KND_Piece and to access extracted data.
 * Usage:
 * $plot_name = 'color-line';
 * $imp = new KND_Import_Remote_Content($plot_name);
 * $imported_data = $imp->import_content();
 *
 *
 */
class KND_Import_Remote_Content {
    
    private $content_importer = NULL;   // remote content imported (depends on content source), now only KND_Import_Git_Content supported
    private $plot_data = NULL;          // array with data, represented as array and KND_Piece
    private $plot_name = NULL;          // plot name, supported values: color-line, right2city, withyou
    private $wizard_plot_name_to_remote_sorce_name = array(
        'problem-org' => 'color-line',
        'fundraising-org' => 'withyou',
        'public-campaign' => 'right2city',
    );
    
    function __construct($plot_name) {
        
        $this->content_importer = new KND_Import_Git_Content();
        
        if(isset($this->wizard_plot_name_to_remote_sorce_name[$plot_name])) {
            $this->plot_name = $this->wizard_plot_name_to_remote_sorce_name[$plot_name];
        }
        
        $this->parsedown = new Parsedown();
    }
    
    public function __get($name) {
        if($name == 'plot_name') {
            return $this->plot_name;
        }
    }
    
    /**
     * Import remote content and extract it into $this->plot_data array, 
     * using $this->content_importer to do all source dependent things.
     *
     */
    function import_content() {
        $this->download_content();
        $this->extract_content();
        
        $this->plot_data = $this->parse_content($this->plot_name);
        return $this->plot_data;
    }
    
    /**
     * Download content using specified importer.
     *
     */
    function download_content() {
        return $this->content_importer->download();
    }
    
    /**
     * Extract content using specified importer.
     *
     */
    function extract_content() {
        return $this->content_importer->extract();
    }
    
    /**
     * Parse extracted content using specified importer.
     *
     */
    function parse_content($plot_name) {
        return $this->content_importer->parse($plot_name);
    }
    
    function import_downloaded_content() {
        $this->plot_data = $this->parse_exist_content($this->plot_name);
        return $this->plot_data;
    }
    
    function parse_exist_content() {
        return $this->content_importer->parse_exist_content($this->plot_name);
    }

    /**
     * Check if piece with name exists in section or not.
     *
     * @param string    $piece_name    The name of the piece.
     * @param string    $section       The name of the section.
     * @return bool
    */
    function is_piece($piece_name, $section = '') {
        
        if($section) {
            return isset($this->plot_data[$this->plot_name][$section][$piece_name]);
        }
        else {
            return isset($this->plot_data[$this->plot_name][$piece_name]);
        }
        
    }
    
    /**
     * Return raw $this->plot_data element by name and section.
     *
     * @param string    $piece_name    The name of the piece.
     * @param string    $section       The name of the section.
     * @return array
    */
    function get_fdata($piece_name, $section = '') {
    
        try {
            if($section) {
                $val = $this->plot_data[$this->plot_name][$section][$piece_name];
            }
            else {
                $val = $this->plot_data[$this->plot_name][$piece_name];
            }
        }
        catch (Exception $ex) {
            $val = NULL;
        }
    
        return $val;
    }
    
    /**
     * Return piece by name and section.
     *
     * @param string    $piece_name    The name of the piece.
     * @param string    $section       The name of the section.
     * @return KND_Piece|NULL
    */
    function get_piece($piece_name, $section = '') {
        
        try {
            if($section) {
                $val = $this->plot_data[$this->plot_name][$section][$piece_name]['piece'];
            }
            else {
                $val = $this->plot_data[$this->plot_name][$piece_name]['piece'];
            }
        }
        catch (Exception $ex) {
            $val = NULL;
        }
        
        return $val;
    }
    
    /**
     * Return piece property by name and section.
     *
     * @param string    $piece_name    The name of the piece.
     * @param string    $key           Piece property name. Possible keys: title, tags, cat, lead, content, thumb, slug.
     * @param string    $section       The name of the section.
     * @return string|int|NULL
    */
    function get_val($piece_name, $key, $section = '') {
        
        $piece = $this->get_fdata($piece_name, $section);
//         print_r($this->plot_data);
        
        try {
            $val = $piece['piece']->$key;
        }
        catch(Exception $ex) {
            $val = NULL;
        }
        
        return $val;
    }
    
    /**
     * Return WP attachment ID of piece thumb.
     *
     * @param KND_Piece    $piece
     * @return int|NULL
    */
    function get_thumb_attachment_id($piece) {
        
        $file_data = NULL;
        
        if(isset($this->plot_data[$this->plot_name][$piece->piece_section][$piece->thumb])) {
            $file_data = $this->plot_data[$this->plot_name][$piece->section_name][$piece->thumb];
        }
        elseif(isset($this->plot_data[$this->plot_name]['img'][$piece->thumb])) {
            $file_data = $this->plot_data[$this->plot_name]['img'][$piece->thumb];
        }
        
        return isset($file_data['attachment_id']) ? $file_data['attachment_id'] : NULL;
    }
    
    /**
     * Parse text with parsedown parser, regexp etc.
     *
     * @param string    $text
     * @return string
    */
    function parse_text($text) {
        
        $new_text = $text;
        
        $new_text = preg_replace("/\/\/(.*?)(\n|$)/", '[knd_r]\1[/knd_r]', $new_text);
        
        $new_text = $this->parsedown->text($new_text);
        
        return $new_text;
    }
}

/**
 * Class to download test data from github, extract, put extracted data into PHP array and KND_Piece and to access extracted data.
 *
 */
class KND_Import_Git_Content {
    
    private $content_archive_url = 'https://github.com/Teplitsa/kandinsky-text/archive/master.zip';
    private $import_content_files_dir = NULL;
    private $zip_fpath = NULL;
    private $content_files = array();
    private $piece_parser = NULL;
    private $distr_attachment_id = NULL;
    
    function __construct() {
        if(!defined('FS_METHOD')) {
            define('FS_METHOD', 'direct');
        }
        
        $this->piece_parser = new KND_Git_Piece_Parser();
    }
    
    /**
     * Download content from github.
     *
     */
    public function download() {
        return $this->download_git_zip();
    }
    
    /**
     * Extract files from archive.
     *
     */
    public function extract() {
        return $this->unzip_git_zip();
    }
    
    /**
     * Extract files from archive.
     *
     */
    public function parse($plot_name) {
        return $this->parse_git_files($plot_name);
    }
    
    public function parse_exist_content($plot_name) {
        
        $exist_attachment = TST_Import::get_instance()->get_attachment_by_old_url( $this->content_archive_url );
        if( $exist_attachment ) {
            
            $this->distr_attachment_id = $exist_attachment->ID;
            $this->zip_fpath = get_post_meta( $this->distr_attachment_id, 'kandinsky_zip_fpath', true );
            $this->import_content_files_dir = get_post_meta( $this->distr_attachment_id, 'kandinsky_import_content_files_dir', true );
            
        }
        
        return $this->parse_git_files($plot_name);
    }
    
    /**
     * Download zip file from github and put it into WP files gallery.
     *
     */
    private function download_git_zip() {
        
        $this->distr_attachment_id = TST_Import::get_instance()->import_big_file( $this->content_archive_url );

        // for debug
//         $this->parse_exist_content();
//         $this->distr_attachment_id = TST_Import::get_instance()->maybe_import( $this->content_archive_url );
//         $this->distr_attachment_id = TST_Import::get_instance()->maybe_import_local_file( '/home/sobranie/php/kandinsky_master.zip' );
//         $this->distr_attachment_id = TST_Import::get_instance()->import_local_file( '/home/sobranie/php/kandinsky_master.zip' );
        
        $this->zip_fpath = get_attached_file( $this->distr_attachment_id );
    }
    
    /**
     * Unzip archive into uploads dir.
     *
     */
    private function unzip_git_zip() {
        
        if(!$this->zip_fpath) {
            throw new Exception("No zip file!");
        }
        
        if(!is_file($this->zip_fpath)) {
            throw new Exception("Zip file not found: {$this->zip_fpath}");
        }
        
        WP_Filesystem();
        $destination = wp_upload_dir();
        $destination_path = $destination['path'];
        $unzipped_dir = $destination_path . '/kandinsky-text-master';
        
        if(is_dir($unzipped_dir)) {
            knd_rmdir($unzipped_dir);
        }
        
//         echo $destination_path . "\n<br />\n";
        $unzipfile = unzip_file( $this->zip_fpath, $destination_path );
        
        if( !is_wp_error($unzipfile) ) {
            $this->import_content_files_dir = $destination_path . '/kandinsky-text-master';
            
            update_post_meta( $this->distr_attachment_id, 'kandinsky_zip_fpath', $this->zip_fpath );
            update_post_meta( $this->distr_attachment_id, 'kandinsky_import_content_files_dir', $this->import_content_files_dir );
            
        } else {
            $this->import_content_files_dir = NULL;
            throw new Exception("Unzip FAILED: {$this->zip_fpath} to {$destination_path} Error: " . var_export($unzipfile, True) );
        }
    }
    
    /**
     * Parse extracted files and put into $this->content_files.
     *
     * @param string    $plot_name    Plot name
     * @return array
     */
    private function parse_git_files($plot_name) {
        
        if(!$this->import_content_files_dir) {
            throw new Exception("No git content dir!");
        }
        
        if(!is_dir($this->import_content_files_dir)) {
            throw new Exception("Unzipped dir not found: {$this->import_content_files_dir}");
        }
        
        $plot_dir = $this->import_content_files_dir . '/' . $plot_name;
        
        if(!is_dir($plot_dir)) {
            throw new Exception("Plot dir not found: {$plot_dir}");
        }
        
        $this->content_files[$plot_name] = $this->scan_content_dir($plot_dir);
        
        return $this->content_files;
    }
    
    /**
     * Recursively scan dir with extracted files and put parsed content into arrays or KND_Piece.
     *
     * @param string    $plot_dir   Dir path
     * @param section   $section    Section name
     * @return array
     */
    private function scan_content_dir($plot_dir, $section = '') {
        
        $plot_dir_listing = scandir($plot_dir);
        $inner_content_files = array();
        
        foreach ($plot_dir_listing as $key => $value) {
        
            if (!in_array($value,array(".", "..", "README.md"))) {
                
                $fpath = $plot_dir . DIRECTORY_SEPARATOR . $value;
                
                if(is_dir($fpath)) {
                    $inner_content_files[$value] = $this->scan_content_dir($fpath, $value);
                }
                else {
                    
                    $file_data = array('file' => $fpath);
                    $piece_name = preg_replace("/\.md$/", "", $value);
                    
                    if(preg_match("/.*\.md$/", $value)) {
                        
                        if(is_file($fpath)) {
                            $piece_data = $this->piece_parser->parse_post( $fpath );
                            $piece_data['piece_name'] = $piece_name;
                            $piece_data['piece_section'] = $section;
                            $file_data['piece'] = new KND_Piece($piece_data);
                        }
                        
                    }
                    elseif(preg_match("/.*\.(svg|jpg|jpeg|png)$/", $value)) {
                        
                        $attachment_id = TST_Import::get_instance()->maybe_import_local_file( $fpath );
                        $file_data['attachment_id'] = $attachment_id;
                        
                    }
                    
                    $inner_content_files[$piece_name] = $file_data;
                }
                
            }
        }
        
        return $inner_content_files;
    }
}

/**
 * Parse local file and put parsed data into array.
 *
 */
class KND_Git_Piece_Parser {
    
    function __construct() {
    }
    
    /**
     * Parse local file.
     *
     * @param string    $fpath   File path
     * @return array
     */
    function parse_post( $fpath ) {
        
        $content = file_get_contents($fpath);
        $content_parts = explode("+++", $content);
        $text = trim(end($content_parts));
        
        $parsed_data = array();
        if( count($content_parts) > 1 ) {
            $header = trim($content_parts[0]);
            $parsed_data = $this->parse_post_header($header);
        }
        
        $parsed_data['content'] = $text;
        
        return $parsed_data;
    }
    
    /**
     * Parse file header, that located before firs +++ string.
     *
     * @param string    $header_text   Header text
     * @return array
     */
    private function parse_post_header($header_text) {
        
        $header_text = trim($header_text);
        $header_lines = explode("\n", $header_text);
        $parsed_data = array();
        
        foreach($header_lines as $k => $line) {
            
            $line_parts = explode("=", $line);
            
            if(count($line_parts) > 0) {
                
                $param_name = trim($line_parts[0]);
                $param_val = trim($line_parts[1]);
                
                if($param_name) {
                    $param_val = trim(trim($param_val, "'\"“”"));
                    $parsed_data[$param_name] = $param_val;
                }
                
            }
        }
        
        return $parsed_data;
    }
}

/**
 * Parsed content item.
 *
 */
class KND_Piece {

    public $title = "";
    public $tags_str = "";
    public $cat_str = "";
    public $thumb = "";
    public $lead = "";
    public $content = "";
    public $slug = "";
    public $url = "";
    
    public $tags = array();
    public $cat = array();
    
    public $piece_section = NULL;
    public $piece_name = NULL;

    function __construct($post_params) {

        $this->title = isset($post_params['title']) ? $post_params['title'] : "";
        $this->thumb = isset($post_params['thumb']) ? $post_params['thumb'] : "";
        $this->lead = isset($post_params['lead']) ? $post_params['lead'] : "";
        $this->content = isset($post_params['content']) ? $post_params['content'] : "";
        $this->slug = isset($post_params['slug']) ? $post_params['slug'] : "";
        $this->url = isset($post_params['url']) ? $post_params['url'] : "";
        
        $this->tags_str = isset($post_params['tags']) ? $post_params['tags'] : "";
        $terms = explode(",", $this->tags_str);
        foreach($terms as $term) {
            $term = trim($term);
            if($term) {
                $this->tags[] = $term;
            }
        }
        
        $this->cat_str = isset($post_params['cat']) ? $post_params['cat'] : "";
        $terms = explode(",", $this->cat_str);
        foreach($terms as $term) {
            $term = trim($term);
            if($term && strtolower($term) != 'uncategorized') {
                $this->cat[] = $term;
            }
        }
        
        $this->piece_name = isset($post_params['piece_name']) ? $post_params['piece_name'] : "";
        $this->piece_section = isset($post_params['piece_section']) ? $post_params['piece_section'] : "";
        
    }
    
    /**
     * Get parsed item slug to use as WP Post name.
     *
     * @return string
     */
    function get_post_slug() {
        
        $slug = "";
        
        if($this->slug) {
            
            $slug = $this->slug;
            
        }
        else {
            
            if($this->piece_section) {
                $slug = $this->piece_section . "-";
            }
            
            $slug .= $this->piece_name;
            
        }
        
        return $slug;
    }
    
}
