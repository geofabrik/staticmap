<?php

/**
 * staticMapLite 0.02
 *
 * Copyright 2009 Gerhard Koch
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
 * @author Gerhard Koch <gerhard.koch AT ymail.com>
 *
 * USAGE: 
 *
 *  staticmap.php?center=40.714728,-73.998672&zoom=14&size=512x512&maptype=default&markers=40.702147,-74.015794,redpin|40.711614,-74.012318,redpin|40.718217,-73.998284,redpin
 *
 */ 

/*error_reporting(0);
ini_set('display_errors','off');
*/


Class staticMapLite {

    protected $tileSize = 256;
    protected $tileSrcUrl = array(
            'print' => 'http://print.tile.geofabrik.de{P}/{Z}/{X}/{Y}.png',
            'print150' => 'http://print.tile.geofabrik.de{P}/{Z}/{X}/{Y}.png',
            'default' => 'http://tile.geofabrik.de{P}/{Z}/{X}/{Y}.png'
            );

    protected $markerLookup = array (
            'default/redpin' => array (
                'filename' => 'default/redpin.png',
                'width' => 39,
                'height' => 39,
                'hotx' => 19,
                'hoty' => 39,
                'textx' => 19,
                'texty' => 16,
                'textsize' => 12,
                'font' => 'LiberationSans-Bold.ttf',
                ),
            'print/redpin' => array (
                'filename' => 'print/redpin.png',
                'width' => 154,
                'height' => 154,
                'hotx' => 77,
                'hoty' => 154,
                'textx' => 75,
                'texty' => 65,
                'textsize' => 45,
                'font' => 'LiberationSans-Bold.ttf',
                ),
            'print150/redpin' => array (
                'filename' => 'print150/redpin.png',
                'width' => 77 ,
                'height' => 77,
                'hotx' => 38,
                'hoty' => 77,
                'textx' => 38,
                'texty' => 33,
                'textsize' => 23,
                'font' => 'LiberationSans-Bold.ttf',
                ),
            );

    protected $tileDefaultSrc = 'mapnik';
    protected $markerBaseDir = 'images';
    protected $fontBaseDir = 'fonts/';
    //protected $osmLogo = 'images/osm_logo.png';

    protected $useTileCache = false;
    protected $tileCacheBaseDir = 'cache/tiles';

    protected $useMapCache = true;
    protected $doNotWriteMapCache = false;
    protected $doNotReadMapCache = false;
    protected $mapCacheBaseDir = 'cache/maps';
    protected $mapCacheID = '';
    protected $mapCacheFile = '';
    protected $mapCacheExtension = 'png';

    protected $zoom, $lat, $lon, $width, $height, $markers, $image, $maptype;
    protected $centerX, $centerY, $offsetX, $offsetY;

    /** Should an attribution text being added at the lower right corner of the image? */
    protected $attribution = false;

    public function __construct(){
        $this->zoom = 0;
        $this->lat = 0;
        $this->lon = 0;
        $this->width = 500;
        $this->height = 350;
        $this->markers = array();
        $this->maptype = $this->tileDefaultSrc;
    }

    public function parseParams(){
        global $_GET;

        // get zoom from GET paramter
        $this->zoom = $_GET['zoom']?intval($_GET['zoom']):0;
        if($this->zoom>18)$this->zoom = 18;

        // get lat and lon from GET paramter
        list($this->lat,$this->lon) = explode(',',$_GET['center']);
        $this->lat = floatval($this->lat);
        $this->lon = floatval($this->lon);

        // get zoom from GET paramter
        if($_GET['size']){
            list($this->width, $this->height) = explode('x',$_GET['size']);
            $this->width = intval($this->width);
            $this->height = intval($this->height);
        }
        if($_GET['markers']){
            $markers = preg_split('/%7C|\|/',$_GET['markers']);
            foreach($markers as $marker){
                list($markerLat, $markerLon, $markerImage) = explode(',',$marker);
                $markerLat = floatval($markerLat);
                $markerLon = floatval($markerLon);
                $markerImage = basename($markerImage);
                $this->markers[] = array('lat'=>$markerLat, 'lon'=>$markerLon, 'image'=>$markerImage);
            }

        }
        if($_GET['maptype']){
            if(array_key_exists($_GET['maptype'],$this->tileSrcUrl)) $this->maptype = $_GET['maptype'];
            if ($_GET['maptype'] == 'print') $this->tileSize = 1024;
            if ($_GET['maptype'] == 'print150') $this->tileSize = 512;
        }
        if(isset($_GET['nocache'])){
            $this->doNotReadMapCache = true;
        }
        if(isset($_GET['attribution']) and $_GET['attribution'] == 'true'){
            $this->attribution = true;
        }
    }

    public function lonToTile($long, $zoom){
        return (($long + 180) / 360) * pow(2, $zoom);
    }

    public function latToTile($lat, $zoom){
        return (1 - log(tan($lat * pi()/180) + 1 / cos($lat* pi()/180)) / pi()) /2 * pow(2, $zoom);
    }

    public function initCoords(){
        $this->centerX = $this->lonToTile($this->lon, ($this->zoom));
        $this->centerY = $this->latToTile($this->lat, ($this->zoom));
        $this->offsetX = floor((floor($this->centerX)-$this->centerX)*$this->tileSize);
        $this->offsetY = floor((floor($this->centerY)-$this->centerY)*$this->tileSize);
    }

    public function createBaseMap(){
        $this->image = imagecreatetruecolor($this->width, $this->height);
        $startX = floor($this->centerX-($this->width/$this->tileSize)/2);
        $startY = floor($this->centerY-($this->height/$this->tileSize)/2);
        $endX = ceil($this->centerX+($this->width/$this->tileSize)/2);
        $endY = ceil($this->centerY+($this->height/$this->tileSize)/2);
        $this->offsetX = -floor(($this->centerX-floor($this->centerX))*$this->tileSize);
        $this->offsetY = -floor(($this->centerY-floor($this->centerY))*$this->tileSize);
        $this->offsetX += floor($this->width/2);
        $this->offsetY += floor($this->height/2);
        $this->offsetX += floor($startX-floor($this->centerX))*$this->tileSize;
        $this->offsetY += floor($startY-floor($this->centerY))*$this->tileSize;

        for($x=$startX; $x<=$endX; $x++){
            for($y=$startY; $y<=$endY; $y++){
                $url = str_replace(array('{P}', '{Z}','{X}','{Y}'),array($_SERVER['PATH_INFO'],$this->zoom, $x, $y), $this->tileSrcUrl[$this->maptype]);
                $tileImage = imagecreatefromstring($this->fetchTile($url));
                $destX = ($x-$startX)*$this->tileSize+$this->offsetX;
                $destY = ($y-$startY)*$this->tileSize+$this->offsetY;
                imagecopy($this->image, $tileImage, $destX, $destY, 0, 0, $this->tileSize, $this->tileSize);
            }
        }
    }


    public function placeMarkers() {
        $white = imagecolorallocate ($this->image, 255, 255, 255);
        $markerIndex=0;
        foreach($this->markers as $marker){
            $markerLat = $marker['lat'];
            $markerLon = $marker['lon'];
            $markerImage = $marker['image'];
            $markerIndex++;
            $mlu = $this->markerLookup[$this->maptype.'/'.$marker['image']];
            $markerFilename = $this->markerBaseDir.'/'.$mlu['filename'];
            if (!file_exists($markerFilename)) return;
            $markerImg = imagecreatefrompng($markerFilename);
            $destX = floor(($this->width/2)-$this->tileSize*($this->centerX-$this->lonToTile($markerLon, $this->zoom)));
            $destY = floor(($this->height/2)-$this->tileSize*($this->centerY-$this->latToTile($markerLat, $this->zoom)));
            $destY = $destY - $mlu['hoty'];
            $destX = $destX - $mlu['hotx'];

            imagecopy($this->image, $markerImg, $destX, $destY, 0, 0, imagesx($markerImg), imagesy($markerImg));

            // determine label width
            $size = imagettfbbox($mlu['textsize'], 0, $this->fontBaseDir.'/'.$mlu['font'], $markerIndex);
            $width = $size[4] - $size[0];

            // place label (1st marker=1 etc)
            imagettftext($this->image, $mlu['textsize'], 0, $destX + $mlu['textx'] - $width/2, $destY + $mlu['texty'], $white, $this->fontBaseDir.'/'.$mlu['font'], $markerIndex);
        };
    }



    public function tileUrlToFilename($url){
        return $this->tileCacheBaseDir."/".str_replace(array('http://'),'',$url);
    }

    public function checkTileCache($url){
        $filename = $this->tileUrlToFilename($url);
        if(file_exists($filename)){
            return file_get_contents($filename);
        }
    }

    public function checkMapCache(){
        if ($this->doNotReadMapCache) return false;
        $this->mapCacheID = md5($this->serializeParams());
        $filename = $this->mapCacheIDToFilename();
        if(file_exists($filename)) return true;
        return false;
    }

    public function serializeParams(){		
        return join("&",array($this->zoom,$this->lat,$this->lon,$this->width,$this->height, serialize($this->markers),$this->maptype));
    }

    public function mapCacheIDToFilename(){
        if(!$this->mapCacheFile){
            $this->mapCacheFile = $this->mapCacheBaseDir."/".substr($this->mapCacheID,0,2)."/".substr($this->mapCacheID,2,2)."/".substr($this->mapCacheID,4);
        }
        return $this->mapCacheFile.".".$this->mapCacheExtension;
    }



    public function mkdir_recursive($pathname, $mode){
        is_dir(dirname($pathname)) || $this->mkdir_recursive(dirname($pathname), $mode);
        return is_dir($pathname) || @mkdir($pathname, $mode);
    }
    public function writeTileToCache($url, $data){
        $filename = $this->tileUrlToFilename($url);
        $this->mkdir_recursive(dirname($filename),0777);
        file_put_contents($filename, $data);
    }

    public function fetchTile($url){
        if($this->useTileCache && ($cached = $this->checkTileCache($url))) return $cached;
        $ch = curl_init(); 
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
        curl_setopt($ch, CURLOPT_TIMEOUT, 300); 
        curl_setopt($ch, CURLOPT_USERAGENT, "staticmaps.php");
        curl_setopt($ch, CURLOPT_URL, $url); 
        if ($tile = curl_exec($ch))
        {
            if($this->useTileCache){
                $this->writeTileToCache($url,$tile);
            }
        }
        else
        {
            $this->doNotWriteMapCache = 1;
        }
        curl_close($ch); 
        return $tile;
    }

    /**
     * Add the copyright notice to the lower right corner of the image.
     *
     * @param font truetype font file to be used, has to be located in the `fonts/` subdirectory
     */
    public function copyrightNotice($font){
        $attributionText = '© OpenStreetMap contributors';
        $bbox = imagettfbbox(8, 0, $this->fontBaseDir . '/' . $font, $attributionText);
        $length = abs($bbox[4] - $bbox[0]);
        $height = abs($bbox[5] - $bbox[1]);
        $black = imagecolorallocate($this->image, 0, 0, 0);
        $transparentWhite = imagecolorallocatealpha($this->image, 255, 255, 255, 60);
        error_log('font: ' . $this->fontBaseDir.'/'.$font);
        imagefilledrectangle($this->image, imagesx($this->image) - $length - 2, imagesy($this->image) - $height - 4, imagesx($this->image), imagesy($this->image), $transparentWhite);
        imagettftext($this->image, 8, 0, imagesx($this->image) - $length - 1, imagesy($this->image) - 4, $black, $this->fontBaseDir.'/'.$font, $attributionText);
    }

    public function sendHeader(){
        header('Content-Type: image/png');
        $expires = 60*60*24*14;
        header("Pragma: public");
        header("Cache-Control: maxage=".$expires);
        header('Expires: ' . gmdate('D, d M Y H:i:s', time()+$expires) . ' GMT');
    }

    public function makeMap(){
        $this->initCoords();		
        $this->createBaseMap();
        if(count($this->markers))$this->placeMarkers();
        if($this->attribution) $this->copyrightNotice('NotoSansUI-Regular.ttf');
    }

    public function showMap(){
        $this->parseParams();
        if($this->useMapCache){
            // use map cache, so check cache for map
            if(!$this->checkMapCache()){
                // map is not in cache, needs to be built
                $this->makeMap();
                $this->sendHeader();	
                if (!$this->doNotWriteMapCache) 
                {
                    $this->mkdir_recursive(dirname($this->mapCacheIDToFilename()),0777);
                    imagepng($this->image,$this->mapCacheIDToFilename(),9);
                }
                if(file_exists($this->mapCacheIDToFilename())){
                    return file_get_contents($this->mapCacheIDToFilename());
                } else {
                    imagepng($this->image);		
                }
            } else {
                // map is in cache
                $this->sendHeader();	
                return file_get_contents($this->mapCacheIDToFilename());
            }

        } else {
            // no cache, make map, send headers and deliver png
            $this->makeMap();
            $this->sendHeader();	
            return imagepng($this->image);		

        }
    }

}

$map = new staticMapLite();
print $map->showMap();

?>
