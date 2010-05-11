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
 * @package HttpCompression
 * @version 1.0-beta
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
   * A list of compression schemes understood by the client
   * @var array
   */
  private $acceptedEncodings = array();

  /**
   * A list of compression schemes supported by the server
   * @var array
   */
  private $supportedEncodings = array('identity');


  public function info() {
    return array(
      'name'        => 'HTTP Output Compression',
      'version'     => '1.0-beta',
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
  private function getEncoding() {
    if(empty($this->acceptedEncodings)) {
      return false;
    }

    $acceptedEncodings = explode(', ', $_SERVER['HTTP_ACCEPT_ENCODING']);
    $supportedEncodings = array(
      'gzip', 'x-gzip', 'deflate', 'compress', 'x-compress', 'bzip2',
    );

    foreach($this->acceptedEncodings as $encoding) {
      if(in_array($encoding, $this->supportedEncodings)) {
        return $encoding;
      }
    }

    return false;
  }

  public function action_init() {
    // Collect encodings supported by the server
    if(function_exists('gzencode')) {
      $this->supportedEncodings[] = 'gzip';
      $this->supportedEncodings[] = 'x-gzip';
    }

    if(function_exists('gzcompress')) {
      $this->supportedEncodings[] = 'compress';
      $this->supportedEncodings[] = 'x-compress';
    }

    if(function_exists('gzdeflate')) {
      $this->supportedEncodings[] = 'deflate';
    }

    if(function_exists('bzcompress')) {
      $this->supportedEncodings[] = 'bzip2';
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
    if($encoding = $this->getEncoding()) {
      $compressionLevel = Options::get('http-compression__compression_level');
      if($compressionLevel === null) {
        $compressionLevel = self::DEFAULT_COMPRESSION_LEVEL;
      }

      if($compressionLevel == 0) {
        header('Content-Length: ' . strlen($buffer));
        return $buffer;
      }

      header('Content-Encoding: ' . $encoding);
      switch($encoding) {
        case 'compress':
        case 'x-compress':
          $buffer = gzcompress($buffer, $compressionLevel);
          break;
        case 'gzip':
        case 'x-gzip':
          $buffer = gzencode($buffer, $compressionLevel);
          break;
        case 'deflate':
          $buffer = gzdeflate($buffer, $compressionLevel);
          break;
        case 'bzip2':
          $buffer = bzcompress($buffer, $compressionLevel);
          break;
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
          $ui->append('static', 'help', _t('Set the level of compression. <em>0</em> means no compression at all, <em>9</em> is the highest possible compression (at the cost of highest CPU consumption.) You need to evaluate this a bit... Usually you\'d want a setting higher than <em>3</em> and lower than <em>7</em>.'));
          $ui->append('radio', 'compression_level', 'option:http-compression__compression_level', _t('Compression level'), 'http-compression');
          $ui->compression_level->options = array(
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
          $ui->append('submit', 'save', _t('Save'));
          $ui->out();
          break;
      }
    }
  }

  public function help() {
    $help = <<< EOT
<p>HTTP Output Compression allows to send compressed content to clients supporting this. See <a href="http://dasourcerer.net/http-output-compression-at-application-level">here</a> for more technical informations on how this works </p>
<p>On the informative side, your <strong>client</strong> signalized support for the following compression schemes:
<ul>
EOT;
    foreach($this->acceptedEncodings as $encoding) {
      $help .= '<li><kbd>' . $encoding . '</kbd></li>';
    }
    $help .= <<< EOT
</ul></p>
<p>Your <strong>server</strong> seems to support the following schemes:
<ul>
EOT;
    foreach($this->supportedEncodings as $encoding) {
      $help .= '<li><kbd>' . $encoding . '</kbd></li>';
    }
    $help .= <<< EOT
</ul></p>
<p>The plugin found the following scheme to be the best fit: 
EOT;
    $help .= $this->getEncoding();
    $help .= '</kbd></p>';
    return $help;
  }
}
?>
