<?php
/**
 *
 * Copyright 2010 Da:Sourcerer
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 */

/**
 * HTTP output compression plugin
 * 
 * @package Plugins
 * @subpackage HttpCompression
 * @version 1.0-beta2
 * @author Da:Sourcerer <http://dasourcerer.net>
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @link http://dasourcerer.net/projects#http-compression
 */
class HttpCompression extends Plugin {
  /**
   * Default compression level. 4 seems to be a good out-of-the-box balue IMHO
   */
  const DEFAULT_COMPRESSION_LEVEL = 4;

  /**
   * A list of possible content encoding names and the functions generating them
   * @var array
   */
  private $encodings = array(
    'gzip'    => 'gzencode',
    'x-gzip'  => 'gzencode',
    'deflate' => 'gzdeflate',
    'bzip2'   => 'bzcompress',
  );

  /**
   * A list of content encodings understood by the client
   * @var array
   */
  private $acceptedEncodings = array();

  /**
   * A list of content encodings supported by the server
   * @var array
   */
  private $supportedEncodings = array('identity');

  public function info() {
    return array(
      'name'        => 'HTTP Output Compression',
      'version'     => '1.0-beta2',
      'url'         => 'http://dasourcerer.net/projects#http-compression',
      'author'      => 'Da:Sourcerer',
      'authorurl'   => 'http://dasourcerer.net',
      'license'     => 'Apache License 2.0',
      'description' => 'Adds HTTP output compression, thus greatly decreasing traffic costs',
    );
  }

  /**
   * Return an encoding supported by the server and understood by the client
   * @return The name of a compression scheme or false if no matching scheme
   *         could be found
   */
  private function get_encoding() {
    if(empty($this->acceptedEncodings)) {
      return false;
    }

    if(ini_get('zlib.output_compression')) {
      return false;
    }

    $acceptedEncodings = explode(', ', $_SERVER['HTTP_ACCEPT_ENCODING']);

    foreach($this->acceptedEncodings as $encoding) {
      if(in_array($encoding, $this->supportedEncodings)) {
        return $encoding;
      }
    }

    return false;
  }

  public function set_priorities() {
    return array(
      'filter_final_output' => 65536,
    );
  }

  public function action_init() {
    // Collect encodings supported by the server
    foreach($this->encodings as $encoding => $function) {
      if(function_exists($function) && Options::get('http-compression__allow_ce_' . $function)) {
        $this->supportedEncodings[] = $encoding;
      }
    }

    // Collect encodings understood by the client
    $encodings = explode(', ', $_SERVER['HTTP_ACCEPT_ENCODING']);
    $acceptedEncodings = array();
    foreach($encodings as $encoding) {
      if(preg_match('/^([^;]+)(?:;q=)?(1(\.0{1,3})|0(\.\d{1,3})?)$/', $encoding,  $matches) == 1) {
        $enc = $matches[1];
        $q   = $matches[2];
      } else {
        $enc = $encoding;
        $q   = 1;
      }

      if($q == 0) {
        // We don't care for encodings *not* supported...
        continue;
      }
      
      if($enc == 'identity') {
        // 'identity' should always be our last choice.
        $q = 0;
      }

      $acceptedEncodings[$enc] = $q;
      arsort($acceptedEncodings, SORT_NUMERIC);
      $this->acceptedEncodings = array_keys($acceptedEncodings);
    }
  }

  public function filter_final_output($buffer) {
    if(headers_sent()) {
      return $buffer;
    }

    if(!empty($this->supportedEncodings)) {
      header('Vary: Accept-Encoding');
    }

    if(Options::get('http-compression__remove_linebreaks')) {
      $buffer = str_replace(array("\r", "\n"), '', $buffer);
    }

    if($encoding = $this->get_encoding()) {
      $compressionLevel = Options::get('http-compression__compression_level');
      if($compressionLevel === null) {
        $compressionLevel = self::DEFAULT_COMPRESSION_LEVEL;
      }

      if($compressionLevel == 0 || strlen($buffer) < Options::get('http-compression__size_threshold')) {
        header('Content-Length: ' . strlen($buffer));
        return $buffer;
      }

      if($encoding == 'identity') {
        header('Content-Encoding: identity');
        header('Content-Length: ' . strlen($buffer));
        return $buffer;
      } else if(array_key_exists($encoding, $this->encodings)) {
        $buffer = call_user_func($this->encodings[$encoding], $buffer, $compressionLevel);
        header('Content-Encoding: ' . $encoding);
        header('Content-Length: ' . strlen($buffer));
        return $buffer;
      }
    }

    header('Content-Length: ' . strlen($buffer));
    return $buffer;
  }

  public function filter_plugin_config($actions, $plugin_id) {
    if($plugin_id == $this->plugin_id()) {
     $actions[] = _t('Configure');
    }
    return $actions;
  }

  public function action_plugin_ui($plugin_id, $action) {
    if($plugin_id == $this->plugin_id()) {
      switch($action) {
        case _t('Configure'):
          $ui = new FormUI(strtolower(get_class($this)));
          $clwrap = $ui->append('fieldset', 'cl', _t('Compression Level', 'http-compression'));
          $clwrap->append('radio', 'compression_level', 'option:http-compression__compression_level', _t('Compression level', 'http-compression'));
          $clwrap->compression_level->options = array(
            '0' => '0',
            '1' => '1',
            '2' => '2',
            '3' => '3',
            '4' => '4',
            '5' => '5',
            '6' => '6',
            '7' => '7',
            '8' => '8',
            '9' => '9',
          );
          $ui->append('text', 'size_threshold', 'option:http-compression__size_threshold', _t('Size Threshold', 'http-compression'));
          $ui->append('checkbox', 'remove_linebreaks', 'option:http-compression__remove_linebreaks', _t('Remove Linebreaks', 'http-compression'));
          $cewrap = $ui->append('fieldset', 'ce', _t('Allowed Compression Methods', 'http-compression'));
          foreach($this->encodings as $encoding => $function) {
            $cewrap->append('checkbox', 'allow_ce_' . $function, 'option:http-compression__allow_ce_' . $function, _t($encoding, 'http-compression'));
          }
          $ui->append('submit', 'save', _t('Save'));
          $ui->out();
          break;
      }
    }
  }

  public function help() {
    $help = <<< EOT
<p>HTTP Output Compression allows to send compressed content to clients supporting this. See <a href="http://dasourcerer.net/http-output-compression-at-application-level">here</a> for more technical informations on how this works.</p>
<p>
The following options are available:
<ul>
  <li><strong>Compression level</strong><br>
    Set the compression level. <kbd>0</kbd> means no compression at all, <kbd>9</kbd> is the highest possible compression (at the cost of highest CPU consumption.) You need to evaluate this a bit... usually you would want a setting higher than <kbd>3</kbd> and lower than <kbd>7</kbd>.</li>
  <li><strong>Size Threshold</strong><br>
    Set a compression threshold. Output having a size smaller than this value will not be compressed. Set this to <kbd>0</kbd> (or a negative value if you are feeling very funny) to disable.</li>
  <li><strong>Remove Linebreaks</strong><br>
    Remove linebreaking control characters (<kbd>\\r</kbd> and <kbd>\\n</kbd>) from the output before compression. This is relatively safe, as linebreaks usually do not have any meaning in XML/HTML. Switching this option on should help improving the compression part a bit. However, the delivered markup will be a bit... illegeble. If you are an evil admin wishing to drive theme designers into insanity, switch this on at all costs.</li>
  <li><strong>Allowed Compression Methods</strong><br>
    You can enabled or disable certain compression methods here. This allows you to immediately disable a misbehaving method.</li>
</ul>
</p>
EOT;
    return _t($help);
  }
}
?>
