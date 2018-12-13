<?php 

namespace Igloo\Gerber;

use Igloo\Gerber\Lib\Size;

class Gerber
{
    private $unzipTemp;
    private $imageTemp;
        
    private $gerbvPath = "gerbv";
    
    private $unzipFolder = null;
    private $imageFolder = null;
    
    private $size = null; 
    private $layers = null;
    private $image = null;
    private $imageLayers = null;
    private $imageThumbnails = null;

    private $files = null;

    //default background color
    private $background = "#f8fafc";

    //colors that top and bottom images will be rendered in
    private $boardRenderColors = [];

    private $layerRenderColors = [
        "slots" => "#000000",
        "top" => "#000000",
        "bottom" => "#000000",
        "internal 1" => "#000000",
        "internal 2" => "#000000",
        "top solder" => "#000000",
        "bottom solder" => "#000000",
        "top paste" => "#000000",
        "bottom paste" => "#000000",
        "outline" => "#000000",
    ];

    private $dpi = [
        "board" => 500,
        "layers" => 500,
    ];

    private $thumbnails = [];
    
    public function  __construct($zipFile, $imageDir = null)
    {
        $this->unzipTemp = sys_get_temp_dir();
        $this->imageTemp = ($imageDir) ?: sys_get_temp_dir();
        
        if(substr($this->imageTemp, -1) === "/")
            $this->imageTemp = substr($this->imageTemp, 0, -1);
        
        $this->unzipFolder = $this->createTempDir($this->unzipTemp);
        $this->imageFolder = $this->createTempDir($this->imageTemp);
        $this->extractZip($zipFile);
        
        $files = array();
        $this->getFiles($this->unzipTemp."/".$this->unzipFolder, $files);
        $separated = $this->separateFiles($files);
        $this->files = $separated["files"];
        $this->layers = $separated["layers"];
    }

    public function __destruct()
    {
        $this->removeDir($this->unzipTemp."/".$this->unzipFolder);
    }

    public function process()
    {
        if(count($this->boardRenderColors) === 0)
        {
            throw new \Exception("No color for rendering top and bottom is set.");
        }
        
        $this->createThumbnailsDir($this->imageTemp."/".$this->imageFolder);

        $image = $this->genImage();
        if(array_key_exists("full", $image["board"]))
            $this->image = $image["board"]["full"];

        if(array_key_exists("full", $image["layers"]))
            $this->imageLayers = $image["layers"]["full"];

        $this->imageThumbnails = [
            'board' => array_key_exists("thumbnails", $image["board"]) ? $image["board"]["thumbnails"] : array(),
            'layers' => array_key_exists("thumbnails", $image["layers"]) ? $image["layers"]["thumbnails"] : array(),
        ];
        $size = $this->determineSize($this->files);
        
        $imgSize = $this->determineSizeFromImage();
        $this->size = ['file' => $size,
                       'image' => $imgSize];
        

    }

    public function setBoardDPI($dpi)
    {
        $this->dpi["board"] = $dpi;
        return $dpi;
    }

    public function getBoardDPI($dpi)
    {
        return $this->dpi["board"];
    }

    public function setLayersDPI($dpi)
    {
        $this->dpi["layers"] = $dpi;
        return $dpi;
    }

    public function getLayersDPI($dpi)
    {
        return $this->dpi["layers"];
    }

    public function setLayerColor($layer, $color)
    {
        if(array_key_exists($layer, $this->layerRenderColors))
        {
            $this->layerRenderColors[$layer] = $color;
            return true;
        }
        return false;
    }

    public function getLayerColor($layer)
    {
        if(array_key_exists($layer, $this->layerRenderColors))
        {
            return $this->layerRenderColors[$layer];
        }
        return null;
    }

    public function setBoardRenderColor($name, $color)
    {
        $this->boardRenderColors[$name] = $color;
        return $color;
    }

    public function getBoardRenderColor($name)
    {
        if(array_key_exists($name, $this->boardRenderColors))
        {
            return $this->boardRenderColors[$name];
        }
        return null;
    }

    public function removeBoardRenderColor($name)
    {
        if(array_key_exists($name, $this->boardRenderColors))
        {
            unset($this->boardRenderColors[$name]);
            return true;
        }
        return false;
    }

    public function setThumbnailSize($width, $height, $blur = 1, $filter = \Imagick::FILTER_BOX)
    {
        return $this->thumbnails[$width."x".$height] = [ 'width' => $width,
                                                         'height' => $height,
                                                         'filter' => $filter,
                                                         'blur' => $blur,
                                                         'path' => null];
    }

    public function getThumbnailSize($width, $height)
    {
        $key = $width."x".$height;
        if(array_key_exists($key))
        {
            return $this->thumbnails[$key];
        }
        return null;
    }

    public function removeThumbnailSize($widht, $height)
    {
        $key = $width."x".$height;
        if(array_key_exists($key))
        {
            unset($this->thumbnails[$key]);
            return true;
        }
        return false;
    }

    public function getBackground()
    {
        return $this->background;
    }

    public function setBackground($color)
    {
        $this->background = $color;
        return $color;
    }
    
    public function getSize()
    {
        return $this->size;
    }

    public function getLayers()
    {
        return $this->layers;
    }

    public function getImage()
    {
        return $this->image;
    }

    public function getImagesByLayers()
    {
        return $this->imageLayers;
    }

    public function getThumbnails()
    {
        return $this->imageThumbnails;
    }

    private function determineSize()
    {
        if($this->files['outline'])
        {
            $outlineFile = $this->unzipTemp."/".$this->unzipFolder."/".$this->files['outline'];
            $sizeParser = new Size($outlineFile);
            $size = $sizeParser->getSize();

            if($size['units'] == "inches")
                $size = $this->convertToMM($size);
            
            //throw aray min and max points that getSize() returns
            $return = ['x' => $size['x'],
                       'y' => $size['y'],
                       'units' => $size['units']];

            return $return;
        }
        else
        {
            $minX = null;
            $maxX = null;
            $minY = null;
            $maxY = null;

            foreach($this->files as $type => $file)
            {
                if($file === null || $type == "drills" || $type == "top silk" || $type == "bottom silk")
                    continue;
                
                $sizeParser = new Size($this->unzipTemp."/".$this->unzipFolder."/".$file);
                $fileSize = $sizeParser->getSize();
                 if($fileSize["units"] === "inches")
                     $fileSize = $this->convertToMM($fileSize);
                 
                if($minX < $fileSize["minX"] || $minX === null)
                    $minX = $fileSize["minX"];
                if($maxX < $fileSize["maxX"] || $maxX === null)
                    $maxX = $fileSize["maxX"];
                if($minY < $fileSize["minY"] || $minY === null)
                    $minY = $fileSize["minY"];
                if($maxY < $fileSize["maxY"] || $maxY === null)
                    $maxY = $fileSize["maxY"];
            }
            $size = ['x' => ($maxX - $minX),
                     'y' => ($maxY - $minY),
                     'units' => "millimeters"];
            return $size;
        }
        
        return null;
    }

    private function determineSizeFromImage()
    {
        if($this->imageLayers && array_key_exists('outline', $this->imageLayers))
        {
            $dpi = $this->dpi["layers"];
            return $this->sizeFromImage($this->imageLayers['outline'], $dpi);
        }
        else
        {
            //images in test file differ in width by 1px
            /*print_r($this->sizeFromImage($images['board']['top']));
              print_r($this->sizeFromImage($images['board']['bottom']));*/
            $dpi = $this->dpi["board"];
            if($this->image)
            {
                return $this->sizeFromImage($this->image[key($this->image)]['top'], $dpi);
            }
            else
            {
                return 0;
            }
        }
    }

    private function separateFiles(&$files)
    {
        $filesArray = [
            "drills" => null,
            "slots" => null, 
            "top" => null,
            "bottom" => null,
            "internal 1" => null,
            "internal 2" => null,
            "top solder" => null,
            "bottom solder" => null,
            "top paste" => null,
            "bottom paste" => null,
            "top silk" => null,
            "bottom silk" => null,
            "outline" => null
        ];
        
        $layers = 0;
        
        foreach($files as $file)
        {
            $ext = strtolower($this->getExtension($file));
            switch($ext)
            {
            case "txt":
                $filesArray["drills"] = $file;
                break;
            case "gml":
                $filesArray["slots"] = $file;
                break;
            case "gtl":
                $filesArray["top"] = $file;
                $layers++;
                break;
            case "gbl":
                $filesArray["bottom"] = $file;
                $layers++;
                break;
            case "g1l":
                $filesArray["internal 1"] = $file;
                $layers++;
                break;
            case "g2l":
                $filesArray["internal 2"] = $file;
                $layers++;
                break;
            case "gts":
                $filesArray["top solder"] = $file;
                break;
            case "gbs":
                $filesArray["bottom solder"] = $file;
                break;
            case "gtp":
                $filesArray["top paste"] = $file;
                break;
            case "gbp":
                $filesArray["bottom paste"] = $file;
                break;
            case "gto":
                $filesArray["top silk"] = $file;
                break;
            case "gbo":
                $filesArray["bottom silk"] = $file;
                break;
            case "goo":
                $filesArray["outline"] = $file;
                break;
            }
        }
        return ["files" => $filesArray,
                "layers" => $layers];
    }

    private function getExtension($filename)
    {
        return pathinfo($filename, PATHINFO_EXTENSION);
    }

    private function getFiles($folder, &$array)
    {
        $handle = opendir($folder);
        while(false !== ($entry = readdir($handle)))
        {
            if(is_dir($folder.'/'.$entry))
            {
                continue;
            }
            else
            {
                $array[] = $entry;
            }
        }
    }

    private function extractZip($fileName)
    {
        $zip = new \ZipArchive();
        if($zip->open($fileName) === true)
        {
            $zip->extractTo($this->unzipTemp."/".$this->unzipFolder);
            return true;
        }
        return false;
    }


    private function createTempDir($base)
    {
        do
        {
            $rand = bin2hex(random_bytes(10));
            $folder = $base."/".$rand;
        }while(!mkdir($folder, 0777, true));
         
        return $rand;
    }

    private function createThumbnailsDir($base)
    {
        foreach($this->thumbnails as $dir => $params)
        {
            $folder = $base.'/'.$dir;
            //possibly unsafe
            mkdir($folder, 0777, true);
            $this->thumbnails[$dir]['path'] = $folder;
        }
    }

    private function removeDir($folder)
    {
        $handle = opendir($folder);
        while(false !== ($entry = readdir($handle)))
        {
            $current = $folder.'/'.$entry;
            if(is_dir($current))
            {
                if ($entry != "." && $entry != "..")
                {
                    $this->removeDir($current);
                }
            }
            else
            {
                unlink($current);
            }
        }
        closedir($handle);
        rmdir($folder);
    }

    private function genImage()
    {
        return ['board' => $this->genBoardImages(),
                'layers' => $this->genLayerImages()];
    }

    private function genBoardImages()
    {
        $boardImages = array();
        $dpi = $this->dpi["board"];
        if(!($this->files["top silk"] || $this->files["top paste"] || $this->files["top solder"] || $this->files["top"]))
        {
            return $boardImages;
        }
        foreach($this->boardRenderColors as $color => $colorCode)
        {
            //render top
            $topOrder = ["top silk" => $this->background,
                         "top paste" => "#B87333",
                         "top solder" => $colorCode,
                         "top" => $colorCode,];
            $filename = "board_top_".$color.".png";
            $boardImages["full"][$color]["top"] = $this->renderImage($topOrder, $filename, $dpi, true);
            $boardImages["thumbnails"][$color]["top"] = $this->renderThumbnails($this->imageTemp."/".$this->imageFolder, $filename);

            //render bottom
            $bottomOrder = ["bottom silk" =>  $this->background,
                            "bottom paste" =>  "#B87333",
                            "bottom solder" => $colorCode,
                            "bottom" => $colorCode,];
            $filename = "board_bottom_".$color.".png";
            $boardImages["full"][$color]["bottom"] = $this-> renderImage($bottomOrder, $filename, $dpi, true);
            $boardImages["thumbnails"][$color]["bottom"] = $this->renderThumbnails($this->imageTemp."/".$this->imageFolder, $filename);
        }

        return $boardImages;
    }

    private function genLayerImages()
    {
        $layerImages = array();
        $dpi = $this->dpi["layers"];
        foreach($this->layerRenderColors as $layer => $color)
        {
            if($this->files[$layer] === null)
                continue;
            $filename = str_replace(" ", "_", $layer).".png";
            $img = $this->renderImage([$layer => $color], $filename, $dpi);
            $layerImages["full"][$layer] = $img;
            $layerImages["thumbnails"][$layer] = $this->renderThumbnails($this->imageTemp."/".$this->imageFolder, $filename);
        }

        return $layerImages;
    }

    function renderImage($renderOrder, $outputFile, $dpi, $trim=false)
    {   
        $allFiles = "";
        
        foreach($renderOrder as $layer => $color)
        {
            if($this->files[$layer] === null)
            {
                continue;
            }
            $allFiles .= "--foreground=".$color." \"".$this->unzipTemp."/".$this->unzipFolder."/".$this->files[$layer]."\" ";
        }
        $exe = $this->genExec($allFiles, $outputFile, $dpi);
        shell_exec($exe);

        //trim silk that goes out of actual PCB
        if($trim)
        {
            $im = new \Imagick($this->imageTemp."/".$this->imageFolder."/".$outputFile);
            $im->trimImage(0);
            $im->writeImage();
            $im->clear();
        }

        return "/".$this->imageFolder."/".$outputFile;
    }

    private function renderThumbnails($imagePath, $filename)
    {
        $images = array();
        foreach($this->thumbnails as $dir => $params)
        {
            $im = new \Imagick($imagePath."/".$filename);
            $resizeTo = $this->determineResizeDimensions($im->getImageWidth(), $im->getImageHeight(), $params['width'], $params['height']);
            $im->resizeImage($resizeTo['width'], $resizeTo['height'], $params['filter'], $params['blur']); //resize while keeping aspect ratio
            $dw = ceil(($params['width']-$resizeTo['width'])/2); //border width
            $dh = ceil(($params['height']-$resizeTo['height'])/2); //border height
            $im->borderImage("#FFFFFF00", $dw, $dh); //fill image to desired width and height with white fully transparent color
            $im->setImagePage(0, 0, 0, 0);
            //if image has odd number of pixels then border adds one pixel
            //more than needed, image is croped to remove it
            $im->cropImage($params['width'], $params['height'], 0, 0);
            $im->writeImage($params['path']."/".$filename);
            $im->clear();
            $images[$dir] = "/".$this->imageFolder."/".$dir."/".$filename;
        }
        return $images;
    }

    private function determineResizeDimensions($currentWidth, $currentHeight, $maxWidth, $maxHeight)
    {
        $s = 1; //scale factor
        $pw = $maxWidth/$currentWidth;
        $ph = $maxHeight/$currentHeight;

        if($pw < $ph)
        {
            $s = $pw;
        }
        else
        {
            $s = $ph;
        }

        return [
            'height' => round($currentHeight*$s),
            'width' => round($currentWidth*$s),
            's' => $s
        ];
    }
    
    private function genExec($files, $output, $dpi)
    {
        $exe = $this->gerbvPath;
        $exe .= " --dpi=".$dpi;
        $exe .= " --background=".$this->background;
        $exe .= " --export=png";
        $exe .= " --output=\"".$this->imageTemp."/".$this->imageFolder."/".$output."\" ";
        $exe .= $files;
        return $exe;
    }
    
    function convertToMM($result)
    {
        return ['x' => $result['x']*25.4,
                'y' => $result['y']*25.4,
                'minX' => $result['minX']*25.4,
                'maxX' => $result['maxX']*25.4,
                'minY' => $result['minY']*25.4,
                'maxY' => $result['maxY']*25.4,
                'units' => 'millimeters'];
    }

    private function sizeFromImage($image, $dpi)
    {
        $im = new \Imagick($this->imageTemp.$image);
        $im->trimImage(0);
        $d = $im->getImageGeometry();
        return ['x' => ($d['width']/$dpi)*25.4,
                'y' => ($d['height']/$dpi)*25.4,
                'units' =>"millimeters"];
    }
}
?>
