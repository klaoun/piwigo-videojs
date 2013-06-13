<?php
/***********************************************
* File      :   function_sync.php
* Project   :   piwigo-videojs
* Descr     :   Generate the admin panel
*
* Created   :   9.06.2013
*
* Copyright 2012-2013 <xbgmsharp@gmail.com>
*
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
************************************************/

// Check whether we are indeed included by Piwigo.
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');


/***************
 *
 * Start the sync work
 *
 */

// Check the presence of the DB schema
$sync_options['sync_gps'] = true;
$q = 'SELECT COUNT(*) as nb FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "'.IMAGES_TABLE.'" AND COLUMN_NAME = "lat" OR COLUMN_NAME = "lon"';
$result = pwg_db_fetch_array( pwg_query($q) );
if($result['nb'] != 2)
{
    $sync_options['sync_gps'] = false;
}

// Init value for result table
$videos = 0;
$metadata = 0;
$thumbs = 0;
$errors = array();
$infos = array();

if (!$sync_options['sync_gps'])
{
    $errors[] = "latitude and longitude disable because the require plugin is not present, eg: 'OpenStreetMap'.";
}

if (!is_file("/usr/bin/ffmpeg") and $sync_options['thumb'])
{
    $errors[] = "Thumbnail creation disable because ffmpeg is not installed on the system, eg: '/usr/bin/ffmpeg'.";
    $sync_options['thumb'] = false;
}

if (!$sync_options['metadata'] and !$sync_options['thumb'])
{
    $errors[] = "You ask me to do nothing, are you sure?";
}

// Avoid Conflict with other plugin using getID3
if( !class_exists('getID3')){
    // Get video infos with getID3 lib
    require_once(dirname(__FILE__) . '/../include/getid3/getid3.php');
}
$getID3 = new getID3;
// Do the job
$result = pwg_query($query);
while ($row = pwg_db_fetch_assoc($result))
{
    //print_r($row);
    $filename = $row['path'];
    if (is_file($filename))
    {
        $videos++;
        //echo $filename;
        $fileinfo = $getID3->analyze($filename);
        //print_r($fileinfo);
        $exif = array();
        if (isset($fileinfo['filesize']))
        {
                $exif['filesize'] = $fileinfo['filesize'];
        }
        /*
        if (isset($fileinfo['playtime_string']))
        {
                $exif['playtime_string'] = $fileinfo['playtime_string'];
        }
        */
        if (isset($fileinfo['video']['resolution_x']))
        {
                $exif['width'] = $fileinfo['video']['resolution_x'];
        }
        if (isset($fileinfo['video']['resolution_y']))
        {
                $exif['height'] = $fileinfo['video']['resolution_y'];
        }
        if (isset($fileinfo['tags']['quicktime']['gps_latitude'][0]) and $sync_options['sync_gps'])
        {
                $exif['lat'] = $fileinfo['tags']['quicktime']['gps_latitude'][0];
        }
        if (isset($fileinfo['tags']['quicktime']['gps_longitude'][0]) and $sync_options['sync_gps'])
        {
                $exif['lon'] = $fileinfo['tags']['quicktime']['gps_longitude'][0];
        }
        if (isset($fileinfo['tags']['quicktime']['model'][0]))
        {
                $exif['Model'] = substr($fileinfo['tags']['quicktime']['model'][0], 2);
        }
        if (isset($fileinfo['tags']['quicktime']['software'][0]))
        {
                $exif['Model'] .= " ". substr($fileinfo['tags']['quicktime']['software'][0], 2);
        }
        if (isset($fileinfo['tags']['quicktime']['make'][0]))
        {
                $exif['Make'] = $fileinfo['tags']['quicktime']['make'][0];
        }
        if (isset($fileinfo['tags']['quicktime']['creation_date'][0]))
        {
                $exif['DateTimeOriginal'] = substr($fileinfo['tags']['quicktime']['creation_date'][0], 1);
        }
        //print_r($exif);
        if (isset($exif) and $sync_options['metadata'])
        {
            $metadata++;
            $infos[] = $filename. ' metadata: '.implode(",", array_keys($exif));
            if ($sync_options['metadata'] and !$sync_options['simulate'])
            {
                $dbfields = explode(",", "filesize,width,height,lat,lon");
                $query = "UPDATE ".IMAGES_TABLE." SET ".vjs_dbSet($dbfields, $exif).", `date_metadata_update`=CURDATE() WHERE `id`=".$row['id'].";";
                pwg_query($query);
            }
        }

        $file_wo_ext = pathinfo($row['file']);
        $output_dir = dirname($row['path']) . '/pwg_representative/';
        $in = $filename;
        $out = $output_dir.$file_wo_ext['filename'].'.jpg';
        if ($sync_options['thumb'])
        {
            $thumbs++;
            $infos[] = $filename. ' thumbnail : '.$out;

            if (!is_dir($output_dir) or !is_writable($output_dir))
            {
                    $errors[] = "Directory ".$output_dir." doesn't exist or wrong permission";
            }
            else if ($sync_options['thumb'] and !$sync_options['simulate'])
            {
                $ffmpeg = "ffmpeg -itsoffset -".$sync_options['thumbsec']." -i ".$in." -vcodec mjpeg -vframes 1 -an -f rawvideo -y ".$out;
                //echo $ffmpeg;
                $log = system($ffmpeg, $retval);
                //$infos[] = $filename. ' thumbnail : retval:'. $retval. ", log:". print_r($log, True);
                if($retval != 0)
                {
                    $errors[] = "Error running ffmpeg, try it manually\n". $ffmpeg;; 
                }
                $query = "UPDATE ".IMAGES_TABLE." SET `representative_ext`='jpg' WHERE `id`=".$row['id'].";";
                pwg_query($query);
            }
        }
    }
}

/***************
 *
 * End the sync work
 *
 */