<?php

namespace Igloo\Gerber\Lib;

use Igloo\Gerber\Lib\Tokenizer;

class Parser
{
    private $tokenizer;
    private $attribute = false;
    
    //buffer used to store command that implicitly closes SR command
    private $buffer = array();

    //holds data about number resolution that is required for
    //converting numbers written in gerber file to numbers in php
    private $resolution =  ['integer' => 3,
                            'decimal' => 3,
                            'total' => 6,
                            'omission' => 'front'];
    
    public function __construct($filename)
    {
        $this->tokenizer = new Tokenizer($filename);
    }

    public function getNext()
    {
        //line that implicitly closed previous tag
        if(count($this->buffer))
        {
            return array_pop($this->buffer);
        }

        //read new line
        while(false !== ($token = $this->tokenizer->read()))
        {
            $command = $this->processToken($token);
            if($command === false)
            {
                continue;
            }

            return $command;
        }
        return false;
    }

    private function processToken(&$token){
        if($token["type"] == "command")
        {
            $command = $token["command"];
            
            $substring = substr($command, 0, 1);
            switch($substring)
            {
            case "X":
            case "Y":
            case "I":
            case "J":
                return $this->handleCoordinates($command);
                break;
            case "D":
                return $this->handleApplyAperiture($command);
                break;
            }

            $substring = substr($command, 0, 2);
            switch($substring){
            case "TF":
                return $this->handleFileAttribute($command);
                break;
            case "AM":
                return $this->handleAperitureMacro($command);
                break;
            case "SR":
                return $this->handleStepRepeat($command);
                break;
            case "AB":
                return $this->handleAperitureBlock($command);
                break;
            case "TA":
                return $this->handleAperitureAttribute($command);
                break;
            case "TD":
                return $this->handleAttributeDelete($command);
                break;
            case "OF":
                return $this->handleOffset($command);
                break;
            case "SF":
                return $this->handleScaleFactor($command);
                break;
            case "LN":
                return $this->handleName($command);
                break;
            case "LM":
                return $this->handleMirroring($command);
                break;
            case "LR":
                return $this->handleRotation($command);
                break;
            case "LS":
                return $this->handleScaling($command);
            }

            $substring = substr($command, 0, 3);
            switch($substring)
            {
            case "G01":
                return $this->handleLinearInterpolation($command);
                break;
            case "G02":
                return $this->handleClockwiseInterpolation($command);
                break;
            case "G03":
                return $this->handleCounterClockwiseInterpolation($command);
                break;
            case "G04":
                return $this->handleComment($command);
                break;
            case "G54":
                return $this->handleSelectAperiture($command);
                break;
            case "ADD":
                return $this->handleDefineAperiture($command);
                break;
            }

            $substring = substr($command, 0, 4);
            switch($substring)
            {
            case "FSLA":
                return $this->handleFrontOmmisionPrecision($command);
                break;
            case "FSTA":
                return $this->handleBackOmmisionPrecision($command);
                break;
            }

            //handle commands which are only one word long
            switch($command)
            {
            case "MOMM":
                return $this->handleMillimeterUnits();
                break;
            case "MOIN":
                return $this->handleInchUnits();
                break;
            case "LPD":
                return $this->handleDarkPolarity();
                break;
            case "LPC":
                return $this->handleClearPolarity();
                break;
            case "IPPOS":
                return $this->handlePositivePolarity();
                break;
            case "IPNEG":
                return $this->handleNegativePolarity();
                break;
            case "G36":
                return $this->handleBeginContour();
                break;
            case "G37":
                return $this->handleEndContour();
                break;
            case "G74":
                return $this->handleSingleQuadrantMode();
                break;
            case "G75":
                return $this->handleMultiQuadrantMode();
                break;
            case "M02":
                return $this->handleEnd();
                break;
            }
        }
        else
        {
            //go to next token
            //opening and closing tags of attributes should not concern us
            return false;
        }
    }

    private function handleCoordinates(&$command)
    {
        $x = null;
        $y = null;
        $i = 0;
        $j = 0;
        $d = null;
                
        if(substr($command, 0, 1) == "X")
        {
            $this->parseCoordinates('X', $x, $command);
        }
                    
        if(substr($command, 0, 1) == "Y")
        {
            $this->parseCoordinates('Y', $y, $command);
        }
                    
        if(substr($command, 0, 1) == "I")
        {
            $this->parseCoordinates('I', $i, $command);
        }

        if(substr($command, 0, 1) == "J")
        {
            $this->parseCoordinates('J', $j, $command);
        }
                    
        sscanf($command, "D%i", $d);
                
        if($d == 1 || $d == null)
        {
            return ['type' => 'draw',
                    'x' => $x,
                    'y' => $y,
                    'i' => $i,
                    'j' => $j];
        }
        else if($d == 2)
        {
            return ['type' => 'move',
                    'x' => $x,
                    'y' => $y,
                    'i' => $i,
                    'j' => $j];
        }
        else if($d == 3)
        {
            return ['type' => 'flash',
                    'x' => $x,
                    'y' => $y,
                    'i' => $i,
                    'j' => $j];
        }
        else
        {
            error_log($d);
            return false;
        }

    }

    private function handleApplyAperiture(&$command)
    {
        $num = (int)substr($command, 1);
        if($num < 10)
        {
            return $this->handleCoordinates($command);
        }
        return ['type' => 'apply aperiture',
                'number' => substr($command, 1)];
    }

    private function handleFileAttribute(&$command)
    {
        $atrs = explode(',', substr($command, 3)); 
                    
        return [
            'type' => 'file attribute',
            'name' => array_slice($atrs, 0, 1)[0],
            'attributes' => array_slice($atrs, 1),
        ];
    }

    private function handleAperitureMacro(&$command)
    {
        $shape = array();
        while(false !== ($subCommand = $this->tokenizer->read()))
        {
            if($subCommand["type"] == "attribute end")
                break;

            if($subCommand["command"][0] == "$")
            {
                sscanf($subCommand["command"], "$%d=%[^\\n]", $var, $expr);
                $shape[] =  [
                    "type" => "assign",
                    "var" => $var,
                    "expr" => $expr,
                ];
            }
            
            $parameters = "";
            sscanf($subCommand["command"], "%d,%[^\\n]", $code, $parameters);
            $parameters = explode(',', $parameters);
            switch($code)
            {
            case 0:
                //comment
                continue;
                break;
            case 1:
                //circle
                $shape[] = [
                    "type" => "circle",
                    "exposure" => $parameters[0],
                    "diameter" => $parameters[1],
                    "centerX" => $parameters[2],
                    "centerY" => $parameters[3],
                    "rotation" => $parameters[4],
                ];
                break;
            case 20:
                //vector line
                $shape[] = [
                    "type" => "vector line",
                    "exposure" => $parameters[0],
                    "width" => $parameters[1],
                    "startX" => $parameters[2],
                    "startY" => $parameters[3],
                    "endX" => $parameters[4],
                    "endY" => $parameters[5],
                    "rotation" => $parameters[6],
                ];
                break;
            case 21:
                //center line
                 $shape[] = [
                    "type" => "center line",
                    "exposure" => $parameters[0],
                    "width" => $parameters[1],
                    "height" => $parameters[2],
                    "centerX" => $parameters[3],
                    "centerY" => $parameters[4],
                    "rotation" => $parameters[5],
                ];
                break;
            case 4:
                //outline
                $pointsArray = array_slice($parameters, 3, 4+2*$parameters[0]);
                $points = array();
                for($i=0;$i<$parameters[1];$i++)
                {
                    $points[] = ["x" => $parameters[2+2*$i],
                                 "y" => $parameters[3+2*$i],];
                }
                $shape[] = [
                    "type" => "outline",
                    "exposure" => $parameters[0],
                    "noVertices" => $parameters[1],
                    "points" => $points,
                    "rotation" => $parameters[4+2*$parameters[1]],
                ];
                break;
            case 5:
                //polygon
                $shape[] = [
                    "type" => "polygon",
                    "exposure" => $parameters[0],
                    "noVertices" => $parameters[1],
                    "centerX" => $parameters[2],
                    "centerY" => $parameters[3],
                    "diameter" => $parameters[4],
                    "rotation" => $parameters[5],
                ];
                break;
            case 6:
                //Moire
                $shape[] = [
                    "type" => "moire",
                    "centerX" => $parameters[0] ?? 0,
                    "centerY" => $parameters[1] ?? 0,
                    "outer diameter" => $parameters[2] ?? 0,
                    "ring thickness" => $parameters[3] ?? 0,
                    "ring gap" => $parameters[4] ?? 0,
                    "noRings" => $parameters[5] ?? 0,
                    "crosshair thickness" => $parameters[6] ?? 0,
                    "crosshair length" => trim($parameters[7]) ?? 0,
                    "rotation" => $parameters[8] ?? 0,
                ];
                break;
            case 7:
                //Thermal
                $shape[] = [
                    "type" => "thermal",
                    "centerX" => $parameters[0],
                    "centerY" => $parameters[1],
                    "outer diameter" => $parameters[2],
                    "inner diameter" => $parameters[3],
                    "thickness" => $parameters[4],
                    "rotation" => $parameters[5],
                    
                ];
                break;
            default:
                error_log("unhandled code in aperiture macro");
                break;
            }
        }
        return [
            'type' => "aperiture macro",
            'name' => substr($command, 2),
            'shape' => $shape,
        ];
    }

    private function handleStepRepeat(&$command)
    {
        if($command == "SR")
        {
            //closing block
            return ['type' => 'repeat end'];
        }
                
        $shape = array();
                
        while(false !== ($subToken = $this->tokenizer->read()))
        {
            $subCommand = $this->processToken($subToken);
            if($subCommand === false)   
            {
                continue;
            }
            
            //start of new SR implcitly closes previous block
            if($subCommand['type'] == 'repeat')
            {
                $this->buffer[] = $subCommand;
                break;
            }

            //M02 (end of gerber file) implicitly closes SR block
            if($subCommand['type'] == 'end')
            {
                $this->buffer[] = $subCommand;
                break;
            }

            if($subCommand['type'] == 'repeat end')
            {
                break;
            }
                    
            $shape[] = $subCommand;      
        }
        //get correct number of repeats and offsets
        sscanf($command, "SRX%dY%dI%gJ%g", $x, $y, $i, $j);

        return ['type' => "repeat",
                'x' => $x,
                'y' => $y,
                'i' => $i,
                'j' => $j,
                'shape' => $shape];

    }

    private function handleAperitureBlock(&$command)
    {
        if($command == "AB")
        {
            //closing block
            return ['type' => 'block aperiture end'];
        }

        sscanf($command, "ABD%d%[^\\n]", $dcode, $command);
        $shape = array();
        
        while(false !== ($subToken = $this->tokenizer->read()))
        {
            $subCommand = $this->processToken($subToken);
            if($subCommand === false)
                continue;
            
            if($subCommand['type'] == 'block aperiture end')
            {
                break;
            }
                    
            $shape[] = $subCommand;
        }

        return ['type' => "block aperiture",
                'dcode' => $dcode,
                'shape' => $shape];
    }

    private function handleAperitureAttribute(&$command)
    {
        return ['type' => 'aperiture attribute',
                'attribute' => substr($command, 2)];
    }

    private function handleAttributeDelete(&$command)
    {
        return ['type' => 'attribute delete',
                'attribute' => substr($command, 2)];
    }

    private function handleOffset(&$command)
    {
        $val = substr($command, 2);
        $a = 0;
        $b = 0;

        if($val[0] == 'A')
        {
            sscanf($val, 'A%D%[^\\n]', $a, $val);
        }

        if($val[0] == 'B')
        {
            sscanf($val, 'B%D%[^\\n]', $b, $val);
        }
        
        return ['type' => "offset",
                'A' => $a,
                'B' => $b];
    }

    private function handleScaleFactor(&$command)
    {
        $val = substr($command, 2);
        $a = 1;
        $b = 1;

        if($val[0] == 'A')
        {
            sscanf($val, 'A%D%[^\\n]', $a, $val);
        }

        if($val[0] == 'B')
        {
            sscanf($val, 'B%D%[^\\n]', $b, $val);
        }
        
        return ['type' => 'scale factor',
                'A' => $a,
                'B' => $b];
    }

    private function handleName(&$command)
    {
        return ['type' => 'name',
                'name' => substr($command, 2)];
    }

    private function handleMirroring(&$command)
    {
        return ['type' => 'mirroring',
                'mirror' => substr($command, 2)];
    }

    private function handleRotation(&$command)
    {
        return ['type' => 'rotation',
                'degrees' => substr($command, 2)];
    }

    private function handleScaling(&$command)
    {
         return ['type' => 'scaling',
                 'scale' => substr($command, 2)];
    }

    private function handleLinearInterpolation(&$command)
    {
        if(strlen($command) > 3)
        {
            //process depreciated "Using G01/G02/G03 in a data block with D01/D02"
            $subCommand = ['type' => 'command',
                           'command' =>  substr($command, 3)];
            $this->buffer[] = $this->processToken($subCommand);
        }
                
        return ['type' => 'interpolation',
                'mode' => 'linear'];
    }

    private function handleClockwiseInterpolation(&$command)
    {
        if(strlen($command) > 3)
        {
            //process depreciated "Using G01/G02/G03 in a data block with D01/D02"
            $subCommand = ['type' => 'command',
                           'command' =>  substr($command, 3)];
            $this->buffer[] = $this->processToken($subCommand);
        }
                
        return ['type' => 'interpolation',
                'mode' => 'circular clockwise'];
    }

    private function handleCounterClockwiseInterpolation(&$command)
    {
        if(strlen($command) > 3)
        {
            //process depreciated "Using G01/G02/G03 in a data block with D01/D02"
            $subCommand = ['type' => 'command',
                           'command' =>  substr($command, 3)];
            $this->buffer[] = $this->processToken($subCommand);
        }
                
        return ['type' => 'interpolation',
                'mode' => 'circular counterclockwise'];
    }

    private function handleComment(&$command)
    {
        return ['type' => 'comment',
                'text' => substr($command, 3)];
    }
    
    private function handleSelectAperiture(&$command)
    {
        $command = substr($command, 3);
        return $this->handleApplyAperiture($command);
    }

    private function handleDefineAperiture(&$command)
    {
        //%[^\\n] matches everything to end of line
        sscanf($command, "ADD%d%[^\\n]", $dcode, $command);
        $values =  explode(',', $command);
        $shape = $values[0];
        $modifiers = null;
                
        if(array_key_exists(1, $values))
        {
            $modifiers = explode('X', $values[1]);
        }
                
        return ['type' => 'define aperiture',
                'dcode' => $dcode,
                'shape' => $shape,
                'modifiers' => $modifiers
        ];    
    }

    private function handleFrontOmmisionPrecision(&$command)
    {
        sscanf($command, "FSLAX%iY%i", $x, $y);
        $x0 = intval(substr($x, 0, 1));
        $x1 = intval(substr($x, 1, 1));
        $this->resolution = [
            'integer' => $x0,
            'decimal' => $x1,
            'total' => $x0+$x1,
            'omission' => 'front'
        ];
        return ['type' => 'precision',
                'x' => $x,
                'y' => $y,
                'omission' => 'front'];
    }

    private function handleBackOmmisionPrecision(&$command)
    {
        sscanf($command, "FSTAX%iY%i", $x, $y);
        $x0 = intval(substr($x, 0, 1));
        $x1 = intval(substr($x, 1, 1));
        $this->resolution = [
            'integer' => $x0,
            'decimal' => $x1,
            'total' => $x0+$x1,
            'omission' => 'back'
        ];
        return ['type' => 'precision',
                'x' => $x,
                'y' => $y,
                'omission' => 'back'];
    }

    private function handleMillimeterUnits()
    {
        return ['type' => 'units',
                'units' => 'millimeters'];

    }

    private function handleInchUnits()
    {
        return ['type' => 'units',
                'units' => 'inches'];
    }

    private function handleDarkPolarity()
    {
         return ['type' => 'polarity',
                 'value' => 'dark'];
    }

    private function handleClearPolarity()
    {
        return ['type' => 'polarity',
                'value' => 'clear'];
    }

    private function handlePositivePolarity()
    {
        return ['type' => 'polarity',
                'value' => 'positive'];
    }

    private function handleNegativePolarity()
    {
        return ['type' => 'polarity',
                'value' => 'negative'];
    }

    private function handleBeginContour()
    {
        $shape = array();

        while(false !== ($subToken = $this->tokenizer->read()))
        {
            $subCommand = $this->processToken($subToken);
            if($subCommand === false)   
            {
                continue;
            }
            
            if($subCommand["type"] == "end contour")
                break;

            $shape[] = $subCommand;
        }
        
        return ['type' => 'contour',
                'shape' => $shape,];
    }

    private function handleEndContour()
    {
        return ['type' => 'end contour'];
    }

    private function handleSingleQuadrantMode()
    {
        return ['type' => 'interpolation mode',
                'mode' => 'single quadrant'];
    }

    private function handleMultiQuadrantMode()
    {
        return ['type' => 'interpolation mode',
                'mode' => 'multi quadrant'];
    }

    private function handleEnd()
    {
        return ['type' => 'end'];
    }

    private function parseCoordinates($char, &$var, &$command)
    {
        sscanf($command, $char."%[^\\n]", $command);
        $sign = '+';
        $commandLen = strlen($command);
        $size = 0;
        for($i = 0; $i<$commandLen; $i++)
        {
            if(ctype_digit($command[$i]) || $command[$i] == "+" || $command[$i] == "-")
            {
                $size++;
                continue;
            }
            break;
         }

        $var = substr($command, 0, $size);
        $command = substr($command, $size);
        if(substr($var, 0, 1) == '-' || substr($var, 0, 1) == '+')
        {
            $sign = substr($var, 0, 1);
            $var = substr($var, 1);
        }
         
         if($this->resolution['omission'] == 'front')
         {
             $var = str_pad($var, $this->resolution['total'], '0', STR_PAD_LEFT);
         }
         else
         {
             $var = str_pad($var, $this->resolution['total'], '0', STR_PAD_RIGHT);
         }
         
         $var = floatval(substr($var, 0, $this->resolution['integer']).'.'.substr($var, $this->resolution['integer']));
         if($sign == '-')
         {
             $var = $var * -1;
         }
    }
}
?>
