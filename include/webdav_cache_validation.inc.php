<?php
if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

function bratonien_tools_webdav_derivative_cache_valid($target_path, $derivative, $params=null, &$reason=null)
{
  $reason = '';
  $target_path = (string)$target_path;
  if ($target_path === '' || !is_file($target_path) || !is_readable($target_path))
  {
    $reason = 'missing';
    return false;
  }

  $cache_root = PHPWG_ROOT_PATH.PWG_DERIVATIVE_DIR;
  if (strpos($target_path, $cache_root) !== 0)
  {
    $reason = 'outside-cache';
    return false;
  }

  clearstatcache(true, $target_path);
  $bytes = @filesize($target_path);
  if ($bytes === false || $bytes < 1)
  {
    $reason = 'empty';
    return false;
  }

  $actual = @getimagesize($target_path);
  if (!is_array($actual) || (int)($actual[0] ?? 0) < 1 || (int)($actual[1] ?? 0) < 1)
  {
    $reason = 'not-image';
    return false;
  }

  if (is_object($derivative) && method_exists($derivative, 'get_size'))
  {
    $expected = $derivative->get_size();
    $expected_width = (int)($expected[0] ?? 0);
    $expected_height = (int)($expected[1] ?? 0);
    if ($expected_width > 0 && $expected_height > 0)
    {
      if ((int)$actual[0] !== $expected_width || (int)$actual[1] !== $expected_height)
      {
        $reason = 'dimension-mismatch:'.(int)$actual[0].'x'.(int)$actual[1].'!='.$expected_width.'x'.$expected_height;
        return false;
      }
    }
  }

  if (is_object($params) && isset($params->last_mod_time))
  {
    $mtime = @filemtime($target_path);
    if ($mtime === false || (int)$mtime < (int)$params->last_mod_time)
    {
      $reason = 'stale-params';
      return false;
    }
  }

  $reason = 'valid';
  return true;
}
