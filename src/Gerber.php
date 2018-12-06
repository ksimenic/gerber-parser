<?php 

namespace Igloo\Gerber;

use Igloo\Gerber\Lib\Size;

class Gerber
{
    private $unzipTemp;
    private $imageTemp;
        
    private $dpi = 1000;
    private $gerbvPath = "gerbv";
    
    private $unzipFolder = null;
    private $imageFolder = null;
    
    private $size = null; 
    private $layers = null;
    private $image = null;
    private $imageLayers = null;


    private $files = null;
    
    public function  __construct($zipFile, $imageDir = null)
    {
        $this->unzipTemp = sys_get_temp_dir();
        $this->imageTemp = ($imageDir) ?: sys_get_temp_dir();
        $this->unzipFolder = $this->createTempDir($this->unzipTemp);
        $this->imageFolder = $this->createTempDir($this->imageTemp);
        $this->extractZip($zipFile);
        
        $files = array();
        $this->getFiles($this->unzipTemp."/".$this->unzipFolder, $files);
        $this->files = $this->separateFiles($files);
        $this->layers = $this->files["layers"];
    }

    public function __destruct()
    {
        $this->removeDir($this->unzipTemp."/".$this->unzipFolder);
    }

    public function process()
    {
        $image = $this->genImage($this->files["files"]);
        $size = $this->determineSize($this->files["files"]);
        
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

    private function determineSize($files)
    {
        if($files['outline'])
        {
            $outlineFile = $this->unzipTemp."/".$this->unzipFolder."/".$files['outline'];
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

            foreach($files as $type => $file)
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

    private function determineSizeFromImage($images)
    {
        if(array_key_exists('outline', $images['layers']))
        {
            return $this->sizeFromImage($images['layers']['outline']);
        }
        else
        {
            //images in test file differ in width by 1px
            /*print_r($this->sizeFromImage($images['board']['top']));
              print_r($this->sizeFromImage($images['board']['bottom']));*/
            return $this->sizeFromImage($images['board']['top']);
        }
    }

    private function separateFiles($files)
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
            $folder = $base.$rand;
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

    private function genImage($files)
    {
        $topOrder = ["top silk" =>  "#FFFFFF",
                     "top paste" =>  "#B87333",
                     "top solder" => "#00FF00",
                     "top" => "#1ba716",];
        $top = $this->renderImage($files, $topOrder, "board_top.png", true);

        $bottomOrder = ["bottom silk" =>  "#FFFFFF",
                        "bottom paste" =>  "#B87333",
                        "bottom solder" => "#00FF00",
                        "bottom" => "#1ba716",];
        $bottom = $this-> renderImage($files, $bottomOrder, "board_bottom.png", true);

        $layers = [
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

        $layerImages = array();
        foreach($layers as $layer => $color)
        {
            if($files[$layer] === null)
                continue;
            $filename = str_replace(" ", "_", $layer).".png";
            $img = $this->renderImage($files, [$layer => $color], $filename);
            $layerImages[$layer] = $img;
        }
        
        return ['board' =>['top' => $top,
                           'bottom' => $bottom,],
                'layers' => $layerImages];
    }

    function renderImage($files, $renderOrder, $outputFile, $trim=false)
    {
        $allFiles = "";
        foreach($renderOrder as $layer => $color)
        {
            if($files[$layer] === null)
            {
                continue;
            }
            $allFiles .= "--foreground=".$color." \"".$this->unzipTemp."/".$this->unzipFolder."/".$files[$layer]."\" ";
        }
        $exe = $this->genExec($allFiles, $outputFile);
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
    
    private function genExec($files, $output)
    {
        $exe = $this->gerbvPath;
        $exe .= " --dpi=".$this->dpi;
        $exe .= " --background=#FFFFFF";
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

    private function sizeFromImage($image)
    {
        $im = new \Imagick($this->imageTemp.$image);
        $im->trimImage(0);
        $d = $im->getImageGeometry();
        return ['x' => ($d['width']/$this->dpi)*25.4,
                'y' => ($d['height']/$this->dpi)*25.4,
                'units' =>"millimeters"];
    }
}
?>
