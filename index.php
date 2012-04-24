<?php

/**
 * Resumable Download Server
 *
 * PROVIDED AS IS. USE AT OWN RESPONSIBILITY.
 *
 * Lists files from a directory and logs files downloaded to a database.
 * Supports partial file request, but only single range. The server will try to
 * recover from errors as good as it can before giving up.
 *
 * server.ini contains the configuration of the server.
 *
 * @version 1.1.0
 * @copyright 2007 Thomas Thomassen
 * http://www.thomthom.net/blog/2007/09/php-resumable-download-server/
 *
 * TODO:
 * - Check the HTTP specs to how to handle certain errors.
 * - Accept multibyte ranges.
 */



/* <NOTICE>
 * Edit the log_download to fit your own server configuration.
 */
function log_download($file)
{
  /*
  include_once('site.inc.php');
  include_once('database.inc.php');

  $query = new DatabaseQuery('downloads');

  $fieldset['file'] = mysql_real_escape_string($file);
  $fieldset['time'] = time();
  $fieldset['ip'] = mysql_real_escape_string($_SERVER['REMOTE_ADDR']);
  $fieldset['ua'] = mysql_real_escape_string($_SERVER['HTTP_USER_AGENT']);

  $query->insert($fieldset);
  */
}
// </NOTICE>



// Server Signature
$serverVersion  = substr($_SERVER['SERVER_SOFTWARE'], 0, strpos($_SERVER['SERVER_SOFTWARE'], ' '));
$serverSignature = "<p><em>$serverVersion Server at {$_SERVER['SERVER_NAME']} Port {$_SERVER['SERVER_PORT']}</em></p>";

// Load Configuration
try
{
  $settings = parse_ini_file('server.ini', true);
  $fileicons = Array(); // We load this on demand
}
catch (Exception $e)
{
  http_error_500('<p>Could not load download server configuration.</p>');
}

// Error reporting
if ($settings['General']['Debug'])
{
  error_reporting(E_ALL | E_STRICT);
}
else
{
  error_reporting(0);
}

// Real root path of where the file repository is. This string will be used
// to make sure files outside the repository isn't accessed.
$validpath = realpath($settings['Server']['Repository']) . DIRECTORY_SEPARATOR;

// If no file is given, list the root
if (!isset($_GET['file']))
{
  list_files($validpath);
  exit;
}

// Get the real name of the requested file
$file = realpath("{$settings['Server']['Repository']}/{$_GET['file']}");

// Make sure that the file requested is located in the $validpath directory
if (file_exists("{$settings['Server']['Repository']}/{$_GET['file']}") &&
    substr($file, 0, strlen($validpath)) == $validpath)
{
  if (is_file($file))
  {
    // If we can't open the file issue an 500 error.
    if (($fp = @fopen($file, 'rb')) === false)
    {
      http_error_500('<p>Could not open requested file.</p>');
    }
    // Now we send some header information describing the connection.
    header('Pragma: public'); // Fix IE6 Content-Disposition
    header('Content-Description: File Transfer');
    header('Content-Transfer-Encoding: binary');
    // Try to extract the mime type by using the PECL Fileinfo extension.
    // Fall back on guessing mime type from the file extension.
    // On any errors, fall back and serve 'text/plain' mime type.
    try
    {
      // Get mime type
      if (extension_loaded('fileinfo'))
      {
        // Fileinfo method
        if ($finfo = finfo_open(FILEINFO_MIME, $settings['Server']['MimeMagic']))
        {
          $mime = finfo_file($finfo, $file);
          finfo_close($finfo);
          header("Content-type: $mime");
        }
      }
      else
      {
        // By file extension
        $mimetypes = parse_ini_file($settings['Server']['MimeSimple']);
        $ex = pathinfo($file, PATHINFO_EXTENSION);
        if (array_key_exists($ex, $mimetypes))
        {
          header("Content-type: {$mimetypes[$ex]}");
        }
        else
        {
          // text/plain or application/octet-stream ?
          header('Content-type: text/plain');
        }
      }
    }
    catch (Exception $e)
    {
      // If anything fails, send default mimetype.
      // Maybe add an option to make default application/octet-stream to
      // prompt browsers Save As dialog?
      header('Content-type: text/plain');
    }
    // Force download dialog if option enabled.
    try
    {
      if ($settings['Server']['ForceDownload'])
      {
        $basename = basename($file);
        $user_agent = $_SERVER['HTTP_USER_AGENT'];
        if ( isset($user_agent) && strstr($user_agent, "MSIE") )
        {
          // Workaround for IE filename bug with multiple periods / multiple
          // dots in filename that adds square brackets to filename.
          // eg. setup.abc.exe becomes setup[1].abc.exe
          $iefilename = preg_replace('/\./', '%2e', $basename, substr_count($basename, '.') - 1);
        }
        header("Content-Disposition: attachment; filename=\"$basename\"");
      }
    }
    catch (Exception $e)
    {
      // Not serious if anything here should cause an error. So we do nothing.
      if ($settings['General']['Debug'])
      {
        echo $e;
      }
    }
    // Find out how much of the file is requested.
    try
    {
      $size   = filesize($file); // File size
      $length = $size;           // Content length
      $start  = 0;               // Start byte
      $end    = $size - 1;       // End byte
      /* Now that we've gotten so far without errors we send the accept range
       * header.
       *
       * At the moment we only support single ranges.
       * Multiple ranges requires some more work to ensure it works correctly
       * and comply with the spesifications:
       *    http://www.w3.org/Protocols/rfc2616/rfc2616-sec19.html#sec19.2
       *
       * Multirange support annouces itself with:
       * header('Accept-Ranges: bytes');
       *
       * Multirange content must be sent with multipart/byteranges mediatype,
       * (mediatype = mimetype)
       * as well as a boundry header to indicate the various chunks of data.
       */
      header("Accept-Ranges: 0-$length");
      if ( isset($_SERVER['HTTP_RANGE']) )
      {
        $c_start = $start;
        $c_end   = $end;
        // Extract the range string
        list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
        // Make sure the client hasn't sent us a multibyte range
        if (strpos($range, ',') !== false)
        {
          // (?) Shoud this be issued here, or should the first range be used?
          //     Or should the header be ignored and we output the whole
          //     content?
          header('HTTP/1.1 416 Requested Range Not Satisfiable');
          header("Content-Range: bytes $start-$end/$size");
          // (?) Echo some info to the client?
          exit;
        }
        // If the range starts with an '-' we start from the beginning.
        // If not, we forward the file pointer and make sure to get the end byte
        // if spesified.
        if ($range{0} == '-')
        {
          // The n-number of the last bytes is requested.
          $c_start = $size - substr($range, 1);
        }
        else
        {
          $range  = explode('-', $range);
          $c_start = $range[0];
          $c_end   = (isset($range[1]) && is_numeric($range[1])) ? $range[1] : $size;
        }
        // Check the range and make sure it's treated according to the specs.
        // http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html
        //
        // End bytes can not be larger than $end.
        $c_end = ($c_end > $end) ? $end : $c_end;
        // Validate the requested range and return an error if it's not correct.
        if ($c_start > $c_end || $c_start > $size - 1 || $c_end >= $size)
        {
          header('HTTP/1.1 416 Requested Range Not Satisfiable');
          header("Content-Range: bytes $start-$end/$size");
          // (?) Echo some info to the client?
          exit;
        }
        // Range is validated at this point.
        $start  = $c_start;
        $end    = $c_end;
        $length = $end - $start + 1; // Calculate new content length
        fseek($fp, $start);
        header('HTTP/1.1 206 Partial Content');
      }
      // Notify the client the byte range we'll be outputting.
      header("Content-Range: bytes $start-$end/$size");
      header("Content-Length: $length");
    }
    catch (Exception $e)
    {
      // We silently ignore error messages and try to output the filee unless
      // we're debugging.
      if ($settings['General']['Debug'])
      {
        echo $e;
      }
    }
    // Start buffered download.
    $buffer = 1024 * 8;
    while(!feof($fp) && ($p = ftell($fp)) <= $end)
    {
      if ($p + $buffer > $end)
      {
        // In case we're only outputtin a chunk, make sure we don't read past
        // the length.
        $buffer = $end - $p + 1;
      }
      set_time_limit(0); // Reset time limit for big files.
      echo fread($fp, $buffer);
      // Free up memory. Otherwise large files will trigger PHP's memory limit.
      flush();
    }
    fclose($fp);

    // Log the download.
    try
    {
      log_download($_GET['file']);
    }
    catch (Exception $error)
    {
      error_log("Error logging download.\n" . $error);
    }
    exit; // Prevents \n to be appended at the end of the script
  }
  elseif ( is_dir($file) )
  {
    list_files($file . DIRECTORY_SEPARATOR);
  }
}
else
{
  // File could not be found. Output 404 error.
  header("HTTP/1.0 404 Not Found");
  echo <<< EOT
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html>
  <head>
    <title>404 Not Found</title>
    <link rel="stylesheet" type="text/css" media="screen" title="Minimal" href="{$settings['General']['Stylesheet']}" />
  </head>
  <body>
    <h1>Not Found</h1>
    <p>
      Go to <a href="{$settings['Server']['URI']}/">{$settings['Server']['Title']}</a>
      or <a href="{$settings['General']['RootURI']}/">{$settings['General']['RootTitle']}</a>.
    </p>
    <p>The requested URL <em>{$_GET['file']}</em> was not found on this server.</p>
    <hr />
    $serverSignature
  </body>
</html>
EOT;
}


/**
 * Outputs an HTML document listing the content of a directory.
 *
 * @param   string  $dir  Directory to list.
 * @return  void
 */
function list_files($dir = '')
{
  global $validpath, $settings;

  // --- Build the breadcrumb navigation bar --- //
  // Remove the repository path
  $path = substr($dir, strlen($validpath));
  $path = explode(DIRECTORY_SEPARATOR, $path);
  // String for <title>
  $title = $path;
  array_unshift($title, 'download');
  $title = implode(' / ', $title);
  // Add HTML links to the navbar
  $tmppath = $settings['Server']['URI'];
  foreach ($path as &$dirpart)
  {
    $tmppath .= '/' . $dirpart;
    $dirpart = "<a href=\"$tmppath/\">$dirpart</a>";
  }
  array_unshift($path, "<a href=\"{$settings['General']['RootURI']}/\">download</a>");
  $navbar = implode(' / ', $path);


  echo <<< EOT
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html>
<head>
  <meta name="robots" content="noindex, nofollow" />
  <title>$title - Download Archive</title>
  <link rel="stylesheet" type="text/css" media="screen" title="Minimal" href="{$settings['General']['Stylesheet']}" />
</head>
<body>

<h1>$navbar</h1>
<p><a href="{$settings['General']['RootURI']}/">Back to {$settings['General']['RootTitle']}</a></p>

<table>
<colgroup width="16">
  <col />
</colgroup>
<colgroup>
  <col />
</colgroup>
<colgroup width="*" align="right">
  <col />
  <col />
</colgroup>
<tr><th colspan="2">Filename</th><th>Type</th><th>Size (Bytes)</th></tr>

EOT;

  if ($dh = opendir($dir))
  {
    $files = Array();
    $dirs  = Array();

    while (($file = readdir($dh)) !== false)
    {
      if (!($dir == $validpath && $file == '..') && $file != '.' && !in_array($file, $settings['FileFilter']))
      {
        $icon = get_fileicon($dir . $file);
        $type = get_filetype($dir . $file);
        $size = get_filesize($dir . $file);
        $path = rawurlencode($file);
        if (is_dir($dir . $file))
        {
          $dirs[] = "<tr><td>$icon</td><td><a href=\"$path/\">$file</a></td><td>$type</td><td>$size</td></tr>\n";
        }
        else
        {
          $files[] = "<tr><td>$icon</td><td><a href=\"$path\">$file</a></td><td>$type</td><td>$size</td></tr>\n";
        }
      }
    }
    closedir($dh);
    natsort($dirs);
    natsort($files);
    echo implode('', $dirs);
    echo implode('', $files);
  }

    echo <<< EOT
</table>
</body>
</html>

EOT;

}

/**
 * Returns XHTML IMG element representing the file. Filetypes and icons are
 * configured with an ini file defined in server.ini.
 *
 * @param   string  $file  Path to the file resource to return information about.
 * @return  string         XHTML IMG element representing the file.
 */
function get_fileicon($file)
{
  global $settings, $fileicons;
  if (is_dir($file))
  {
    return "<img src=\"{$settings['General']['IconPath']}/folder.png\" alt=\"Folder:\" />";
  }
  elseif (is_file($file))
  {
    $type = get_filetype($file);
    // Load ini file on demand
    if (!count($fileicons))
    {
      $fileicons = parse_ini_file($settings['General']['IconConfig']);
    }
    // Get icon
    if (array_key_exists($type, $fileicons))
    {
      $image = $fileicons[$type];
    }
    else
    {
      $image = 'file.png';
    }
    return "<img src=\"{$settings['General']['IconPath']}/$image\" alt=\"File:\" />";
  }
  else
  {
    return "<img src=\"{$settings['General']['IconPath']}/unknown.png\" alt=\"Unknown:\" />";
  }
}

/**
 * Takes a file resource and returns a string describing it's type.
 *
 * @param   string  $file  Path to the file resource to return information about.
 * @return  string         The file extension if it's a file, 'Folder' if it's a directory, or '?' upon unknown resource.
 */
function get_filetype($file)
{
  if (is_dir($file))
  {
    return 'Folder';
  }
  elseif (is_file($file))
  {
    $fp = pathinfo($file);
    return $fp['extension'];
  }
  return '?';
}

/**
 * Returns the file size of the given file.
 *
 * @param   string  $file  Path to the file resource to return information about.
 * @return  string         Formatted string of the file's size.
 */
function get_filesize($file)
{
  global $settings;
  if (is_file($file))
  {
    $size = filesize($file);
    if ($settings['General']['FriendlyUnits'])
    {
      return filesize_format($size);
    }
    return number_format($size);
  }
  return '-';
}

/**
 * Format a number of bytes into a human readable format.
 * Optionally choose the output format and/or force a particular unit
 *
 * http://www.phpriot.com/d/code/strings/filesize-format/index.html
 *
 * @param   int     $bytes    The number of bytes to format. Must be positive
 * @param   string  $format   Optional. The output format for the string
 * @param   string  $force    Optional. Force a certain unit. B|KB|MB|GB|TB
 * @return  string            The formatted file size
 */
function filesize_format($bytes, $format = '', $force = '')
{
  $force = strtoupper($force);
  $defaultFormat = '%01d %s';
  if (strlen($format) == 0)
    $format = $defaultFormat;

  $bytes = max(0, (int) $bytes);

  $units = array('B', 'KB', 'MB', 'GB', 'TB', 'PB');

  $power = array_search($force, $units);

  if ($power === false)
    $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;

  return sprintf($format, $bytes / pow(1024, $power), $units[$power]);
}

/**
 * This function is from the PHP manual's comments on readfile()
 * I prevents the script from hitting the memory limit when reading large files.
 * Apparently, in PHP5.0.x only 2MB will be delivered with readfile() and fpassthru().
 *
 * @param   string  $filename  The file to read.
 * @param   bool    $retbytes  Optional. Whether the functions returns the number of bytes read.
 * @return  bool || int       By default number of bytes read, if $retbytes is set to false it returns true on success or false on failure.
 */
function readfile_chunked($filename, $retbytes = true)
{
  $chunksize = 1 * (1024 * 1024); // how many bytes per chunk
  $buffer = '';
  $cnt = 0;
  $handle = fopen($filename, 'rb');
  if ($handle === false)
  {
    return false;
  }
  while (!feof($handle))
  {
    $buffer = fread($handle, $chunksize);
    echo $buffer;
    flush();
    if ($retbytes)
    {
      $cnt += strlen($buffer);
    }
  }
  $status = fclose($handle);
  if ($retbytes && $status)
  {
    return $cnt; // return num. bytes delivered like readfile() does.
  }
  return $status;
}


/**
 * Outputs an HTTP 500 message with the provided $message.
 *
 * @param   string  $message
 * @return  void
 */
function http_error_500($message)
{
  header('HTTP/1.0 500 Internal Server Error');
  echo <<< EOT
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html>
  <head>
    <title>500 Internal Server Error</title>
  </head>
  <body>
    <h1>Internal Server Error</h1>
    $message
    <hr />
    <p><em>$version Server at {$_SERVER['SERVER_NAME']} Port {$_SERVER['SERVER_PORT']}</em></p>
  </body>
</html>
EOT;
  exit;
}

?>