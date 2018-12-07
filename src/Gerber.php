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


    private $files = null;

    //default background color
    private $background = "#f8fafc";

    //colors that top and bottom images will be rendered in
    private $boardRenderColors = [
        "green" => "#00FF00",
        "blue" => "#0000FF",
        "yellow" => "#FFFF00",
    ];

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

    //dpi by layers that should be different than default dpi
    private $dpi = [
        "board" => 1000,
        "layers" => 1000,
    ];
    
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
        $image = $this->genImage();
        $size = $this->determineSize($this->files);
        
        $imgSize = $this->determineSizeFromImage($image);
        $this->size = ['file' => $size,
                       'image' => $imgSize];
        
        $this->image = $image["board"];
        $this->imageLayers = $image["layers"];
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

    private function determineSizeFromImage(&$images)
    {
        if(array_key_exists('outline', $images['layers']))
        {
            $dpi = $this->dpi["layers"];
            return $this->sizeFromImage($images['layers']['outline'], $dpi);
        }
        else
        {
            //images in test file differ in width by 1px
            /*print_r($this->sizeFromImage($images['board']['top']));
              print_r($this->sizeFromImage($images['board']['bottom']));*/
            $dpi = $this->dpi["board"];
            return $this->sizeFromImage($images['board'][key($images['board'])]['top'], $dpi);
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
        foreach($this->boardRenderColors as $color => $colorCode)
        {
            //render top
            $topOrder = ["top silk" => $this->background,
                         "top paste" => "#B87333",
                         "top solder" => $colorCode,
                         "top" => $colorCode,];
            $boardImages[$color]["top"] = $this->renderImage($topOrder, "board_top_".$color.".png", $dpi, true);

            //render bottom
            $bottomOrder = ["bottom silk" =>  $this->background,
                            "bottom paste" =>  "#B87333",
                            "bottom solder" => $colorCode,
                            "bottom" => $colorCode,];
            $boardImages[$color]["bottom"] = $this-> renderImage($bottomOrder, "board_bottom_".$color.".png", $dpi, true);
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
            $layerImages[$layer] = $img;
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
        }
        return "/".$this->imageFolder."/".$outputFile;
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
