<?php
require_once('parser.php');

class size
{
    private $aperitures = array();
    private $macros = array();
    private $units = "millimeters";
    
    private $parser;

    private $scaleFactor = ['A' => 1,
                            'B' => 1];

    private $offset = ['A' => 0,
                       'B' => 0];
    
    public function __construct($filename)
    {
        $this->parser = new parser($filename);
    }

    public function getSize()
    {
        $frame = $this->createNewFrame();
        while(false !== ($command = $this->parser->getNext()))
        {
            $action = $this->processCommand($command, $frame);
            if($action["action"] == "apply")
            {
                $this->processApplyAction($action, $frame);
            }
            else if($action["action"] == "continue")
            {
                continue;
            }
            else if($action["action"] == "end")
            {
                break;
            }
            else
            {
                error_log("unhandled action".print_r($action, true));
            }
        }
        return (['x' => ($frame["maxX"] - $frame["minX"])*$this->scaleFactor['A'],
                 'y' => ($frame["maxY"] - $frame["minY"])*$this->scaleFactor['B'],
                 'minX' => $frame["minX"] * $this->scaleFactor['A'] + $this->offset['A'],
                 'maxX' => $frame["maxX"] * $this->scaleFactor['A'] + $this->offset['A'],
                 'minY' => $frame["minY"] * $this->scaleFactor['B'] + $this->offset['B'],
                 'maxY' => $frame["maxY"] * $this->scaleFactor['B'] + $this->offset['B'],
                 'units' => $this->units,]);
    }
    private function createNewFrame()
    {
        return [
            'maxX' => null,
            'minX' => null,
            'maxY' => null,
            'minY' => null,
            'posX' => 0,
            'posY' => 0,
            'aperiture' => null,
            'rotation' => 0,
            'scaling' => 1,
            'interpolation' => "linear",
            'interpolation mode' => null,
            'mirror' => "N",
        ];
    }
    private function processApplyAction(&$action, &$frame)
    {
        if(array_key_exists("size", $action))
        {
            if($action["size"]["minX"] != 0 && ($frame["minX"] > $frame["posX"] + $action["size"]["minX"] || $frame["minX"] === null))
                $frame["minX"] = $frame["posX"] + $action["size"]["minX"];

            if($action["size"]["maxX"] != 0 && ($frame["maxX"] < $frame["posX"] + $action["size"]["maxX"] || $frame["maxX"] === null))
                $frame["maxX"] = $frame["posX"] + $action["size"]["maxX"];

            if($action["size"]["minY"] != 0 && ($frame["minY"] > $frame["posY"] + $action["size"]["minY"] || $frame["minY"] === null))
                $frame["minY"] = $frame["posY"] + $action["size"]["minY"];

            if($action["size"]["maxY"] != 0 && ($frame["maxY"] < $frame["posY"] + $action["size"]["maxY"] || $frame["maxY"] === null))
                $frame["maxY"] = $frame["posY"] + $action["size"]["maxY"];
 
        }
        if(array_key_exists("move", $action))
        {
            if(array_key_exists("x", $action["move"]))
                $frame["posX"] = $action["move"]["x"];
            if(array_key_exists("y", $action["move"]))
                $frame["posY"] = $action["move"]["y"];
        }
    }

    private function processCommand(&$command, &$frame)
    {
        switch($command["type"])
        {
        case "name":
        case "comment":
        case "polarity":
        case "precision":
        case "file attribute":
        case "aperiture attribute":
        case "attribute delete":
            return ["action" => "continue"];
            break;
        case "move":
            return $this->handleMove($command);
            break;
        case "draw":
            return $this->handleDraw($command, $frame);
            break;
        case "flash":
            return $this->handleFlash($command, $frame);
            break;
        case "define aperiture":
            return $this->handleDefineAperiture($command);
            break;
        case "apply aperiture":
            return $this->handleApplyAperiture($command, $frame);
            break;
        case "aperiture macro":
            return $this->handleAperitureMacro($command);
            break;
        case "block aperiture":
            return $this->handleBlockAperiture($command, $frame);
            break;
        case "interpolation":
            return $this->handleInterpolation($command, $frame);
            break;
        case "interpolation mode":
            return $this->handleInterpolationMode($command, $frame);
            break;
        case "units":
            return $this->handleUnits($command);
            break;
        case "end":
            return ["action" => "end"];
            break;
        case "repeat":
            return $this->handleRepeat($command, $frame);
            break;
        case "rotation":
            return $this->handleRotation($command, $frame);
            break;
        case "scaling":
            return $this->handleScaling($command, $frame);
            break;
        case "mirroring":
            return $this->handleMirror($command, $frame);
            break;
        case "contour":
            return $this->handleContour($command, $frame);
            break;
        case "scale factor":
            return $this->handleScaleFactor($command);
            break;
        case "offset":
            return $this->handleOffset($command);
            break;
        default:
            error_log("unhandled command ".print_r($command,true));
            return ["action" => "continue"];
            break;
        }
    }

    private function handleScaleFactor($command)
    {
        $this->scaleFactor = ['A' => $command['A'],
                              'B' => $command['B']];
        
        return ["action" => "continue"];
    }

    private function handleOffset($command)
    {
        $this->offset = ['A' => $command['A'],
                         'B' => $command['B']];
        
        return ["action" => "continue"];
    }

    private function handleContour(&$command, &$frame)
    {
        
        $localFrame = $frame;
        $localFrame["aperiture"]["maxX"] = 0;
        $localFrame["aperiture"]["minX"] = 0;
        $localFrame["aperiture"]["maxY"] = 0;
        $localFrame["aperiture"]["minY"] = 0;
    
        foreach($command["shape"] as &$subCommand)
        {
            $action = $this->processCommand($subCommand, $localFrame);
            if($action["action"] == "apply")
            {
                $this->processApplyAction($action, $localFrame);
            }
            else if($action["action"] == "continue")
            {
                continue;
            }
            else if($action["action"] == "end")
            {
                break;
            }
            else
            {
                error_log("unhandled action in contour, action:".print_r($action, true)." command:".print_r($subCommand)."\n");
            }
        }

        $localFrame["aperiture"] = $frame["aperiture"];

        $frame = $localFrame;
        
        return ["action" => "continue"];
    }
    
    private function handleDefineAperiture(&$command)
    {
        if($command['shape'] == 'C')
        {
            $diameter = $command["modifiers"][0];
            $radius = $diameter/2;
            $this->aperitures[$command["dcode"]] = ["type" => "circle",
                                                    "dcode" => $command["dcode"],
                                                    "radius" => $radius,
                                                    "x" => 0,
                                                    "y" => 0,];
            return ["action" => "continue"];
        }
        if($command['shape'] == 'R')
        {
            $x = $command["modifiers"][0]/2;
            $y = $command["modifiers"][1]/2;
            $points = [
                ['x' => $x, 'y' => $y],
                ['x' => $x, 'y' => -$y],
                ['x' => -$x, 'y' => $y],
                ['x' => -$x, 'y' => -$y]
            ];
            $this->aperitures[$command["dcode"]] = ["type" => "polygon",
                                                    "dcode" => $command["dcode"],
                                                    "points" => $points];
            return ["action" => "continue"];
        }
        if($command['shape'] == 'O')
        {
            //this shape could be represented with square and two circles
            $x = $command["modifiers"][0]/2;
            $y = $command["modifiers"][1]/2;
            $s = null;
            if($x > $y)
            {
                $s = $y;
                $circle1 = ["type" => "circle",
                            "radius" => $x-$y,
                            "x" => $y,
                            "y" => 0,];
                $circle2 = ["type" => "circle",
                            "radius" => $x-$y,
                            "x" => -$y,
                            "y" => 0,];
            }
            else
            {
                $s = $x;
                $circle1 = ["type" => "circle",
                            "radius" => $y-$x,
                            "x" => 0,
                            "y" => $x,];
                $circle2 = ["type" => "circle",
                            "radius" => $y-$x,
                            "x" => 0,
                            "y" => -$x,];
            }
            
            $squarePoints = [
                ['x' => $s, 'y' => $s],
                ['x' => $s, 'y' => -$s],
                ['x' => -$s, 'y' => $s],
                ['x' => -$s, 'y' => -$s]
            ];

            $square = ["type" => "polygon",
                       "dcode" => $command["dcode"],
                       "points" => $squarePoints];
            
            $this->aperitures[$command["dcode"]] = ["type" => "combination",
                                                    "dcode" => $command["dcode"],
                                                    "sub shapes" => [
                                                        $circle1,
                                                        $circle2,
                                                        $square
                                                    ]];
            return ["action" => "continue"];
        }
        if($command['shape'] == 'P')
        {
            $radius = $command["modifiers"][0]/2;
            $numberOfPoints = $command["modifiers"][1];
            $rotation = 0;
            if(array_key_exists(2, $command["modifiers"]))
            {
                $rotation = $command["modifiers"][2];
            }

            $x = $radius;
            $y = 0;

            $points = array();
            for($i=0; $i<$numberOfPoints; $i++)
            {
               
                $angle = deg2rad($i * (360/$numberOfPoints) + $rotation);
                $points[] = ['x' => ($x * cos($angle) - $y * sin($angle)),
                             'y' => ($y * cos($angle) + $x * sin($angle))];
            }
            
            $this->aperitures[$command["dcode"]] = ["type" => "polygon",
                                                    "dcode" => $command["dcode"],
                                                    "points" => $points];
            return ["action" => "continue"];
        }

        if(array_key_exists($command['shape'], $this->macros))
        {
            $modifiers = $command["modifiers"] ?? array();
            $subShapes = $this->handleDefineMacro($modifiers, $this->macros[$command["shape"]]);
            $this->aperitures[$command["dcode"]] = ["type" => "combination",
                                                    "dcode" => $command["dcode"],
                                                    "sub shapes" => $subShapes,];
            return ["action" => "continue"];
        }
            
        error_log("unknown aperiture defined ".print_r($command, true));
        return ["action" => "continue"];
    }

    private function handleApplyAperiture(&$command, &$frame)
    {
        $aperiture = $this->aperitures[$command["number"]];
        $size = $this->determineSize($aperiture, $frame);
        if($frame["mirror"] == "N")
        {
            $aperiture["minX"] = $size["minX"];
            $aperiture["maxX"] = $size["maxX"];
            $aperiture["minY"] = $size["minY"];
            $aperiture["maxY"] = $size["maxY"];
        }
        else if( $frame["mirror"] == "X")
        {
            $aperiture["minX"] = -$size["maxX"];
            $aperiture["maxX"] = -$size["minX"];
            $aperiture["minY"] = $size["minY"];
            $aperiture["maxY"] = $size["maxY"];
        }
        else if( $frame["mirror"] == "Y")
        {
            $aperiture["minX"] = $size["minX"];
            $aperiture["maxX"] = $size["maxX"];
            $aperiture["minY"] = -$size["maxY"];
            $aperiture["maxY"] = -$size["minY"];
        }
        else if( $frame["mirror"] == "XY")
        {
            $aperiture["minX"] = -$size["maxX"];
            $aperiture["maxX"] = -$size["minX"];
            $aperiture["minY"] = -$size["maxY"];
            $aperiture["maxY"] = -$size["minY"];
        }

        $frame["aperiture"] = $aperiture;
        return ["action" => "continue"];
    }
    
    private function handleAperitureMacro(&$command)
    {
        $this->macros[$command["name"]] = $command["shape"];
        return ["action" => "continue"];
    }

    private function handleBlockAperiture(&$command, &$frame)
    {
        $shape = array();
        foreach($command["shape"] as &$subCommand)
        {
            if($subCommand["type"] === "block aperiture")
            {
                $this->handleBlockAperiture($subCommand, $frame);
            }
            else if($subCommand["type"] == "define aperiture" )
            {
                $this->handleDefineAperiture($subCommand);
            }
            else
            {
                $shape[] = $subCommand;
            }
        }
        $this->aperitures[$command["dcode"]] = ["type" => "block",
                                                "shape" => $command["shape"],
                                                "dcode" => $command["dcode"]];
        return ["action" => "continue"];
    }

    private function handleDefineMacro(&$modifiers, &$macroShapes)
    {
        $shapes = array();
        foreach($macroShapes as $shape)
        {
            switch($shape["type"])
            {
            case "assign":
                $modifiers[$shape["var"]-1] = $this->handleArith($shape["expr"], $modifiers);
                break;
            case "circle":
                $x = $this->handleArith($shape["centerX"], $modifiers);
                $y = $this->handleArith($shape["centerY"], $modifiers);

                $rotation = ["rotation" => $this->handleArith($shape["rotation"], $modifiers)];
                $center = ['x' => $x, 'y' => $y];
                $center = $this->rotatePoint($center, $rotation);
                $shapes[] = [
                    "type" => "circle",
                    "radius"=> $this->handleArith($shape["diameter"], $modifiers)/2,
                    "x" => $center['x'],
                    "y" => $center['y'],
                ];
                break;
            case "vector line":
                $startX = $this->handleArith($shape["startX"], $modifiers);
                $endX = $this->handleArith($shape["endX"], $modifiers);
                $startY = $this->handleArith($shape["startY"], $modifiers);
                $endY = $this->handleArith($shape["endY"], $modifiers);
                $width = $this->handleArith($shape["width"], $modifiers)/2;

                $angle = $this->getAngle($startX, $startY, $endX, $endY);

                $offsets = ['x' => ($width * sin($angle)),
                            'y' => ($width * cos($angle)),];
                
                $points = [['x' => $startX+$offsets['x'],
                            'y' => $startY+$offsets['y']],
                           ['x' => $startX-$offsets['x'],
                            'y' => $startY-$offsets['y']],
                           ['x' => $endX+$offsets['x'],
                            'y' => $endY+$offsets['y']],
                           ['x' => $endX-$offsets['x'],
                            'y' => $endY-$offsets['y']]];
                
                $rotatedPoints = array();
                $rotation = ["rotation" => $this->handleArith($shape["rotation"], $modifiers)];
                foreach($points as $point)
                {
                    
                    $rotatedPoints[] = $this->rotatePoint($point, $rotation);
                }
                
                $shapes[] = [
                    "type" => "polygon",
                    "points" => $rotatedPoints,
                ];
                break;
            case "center line":
                $centerX = $this->handleArith($shape["centerX"], $modifiers);
                $centerY = $this->handleArith($shape["centerY"], $modifiers);
                $width = $this->handleArith($shape["width"], $modifiers)/2;
                $height = $this->handleArith($shape["height"], $modifiers)/2;
                $rotation = ["rotation" => $this->handleArith($shape["rotation"], $modifiers)];
                
                $points = [['x' => $centerX+$width,
                            'y' => $centerY+$height],
                           ['x' => $centerX+$width,
                            'y' => $centerY-$height],
                           ['x' => $centerX-$width,
                            'y' => $centerY+$height],
                           ['x' => $centerX-$width,
                            'y' => $centerY-$height],
                ];

                $rotatedPoints = array();
                foreach($points as $point)
                {
                    
                    $rotatedPoints[] = $this->rotatePoint($point, $rotation);
                }
                
                $shapes[] = [
                    "type" => "polygon",
                    "points" => $rotatedPoints,
                ];
                break;
            case "outline":
                $points = array();
                foreach($shape["points"] as $point)
                {
                    $points[] = ['x' => $this->handleArith($point["x"], $modifiers),
                                 'y' => $this->handleArith($point["y"], $modifiers),];
                }
                $shapes[] = [
                    "type" => "polygon",
                    "points" => $points,
                ];
                break;
            case "polygon":
                $radius = $this->handleArith($shape["diameter"], $modifiers)/2;
                $centerX = $this->handleArith($shape["centerX"], $modifiers);
                $centerY = $this->handleArith($shape["centerY"], $modifiers);
                $rotation = $this->handleArith($shape["rotation"], $modifiers);
                $numberOfPoints =  $this->handleArith($shape["noVertices"], $modifiers);
                
                $points = array();
                $x = $radius;
                $y = 0;
                for($i=0; $i<$numberOfPoints; $i++)
                {
                    $angle = deg2rad($i * (360/$numberOfPoints) + $rotation);
                    $points[] = ['x' => $centerX + ($x * cos($angle) - $y * sin($angle)),
                                 'y' => $centerY + ($y * cos($angle) + $x * sin($angle))];
                }
                $shapes[] = [
                    "type" => "polygon",
                    "points" => $points,
                ];
                break;
            case "moire":
                $centerX = $this->handleArith($shape["centerX"], $modifiers);
                $centerY = $this->handleArith($shape["centerY"], $modifiers);
                $radius = $this->handleArith($shape["diameter"], $modifiers)/2;
                $length = $this->handleArith(($shape["crosshair length"]), $modifiers)/2;
                $thickness = $this->handleArith(($shape["crosshair thickness"]), $modifiers)/2;
                
                //outer circle
                $shapes[] = [
                    "type" => "circle",
                    "radius"=> $radius,
                    "x" => $centerX,
                    "y" => $centerY,
                ];

                //cross
                $shapes[] = [
                    "type" => "polygon",
                    "points" => [
                        ['x' => $centerX+$length,
                         'y' => $centerY+$thickness,],
                        ['x' => $centerX+$length,
                         'y' => $centerY-$thickness,],
                        ['x' => $centerX-$length,
                         'y' => $centerY+$thickness,],
                        ['x' => $centerX-$length,
                         'y' => $centerY-$thickness,],
                        ['x' => $centerX+$thickness,
                         'y' => $centerY+$length,],
                        ['x' => $centerX+$thickness,
                         'y' => $centerY-$length,],
                        ['x' => $centerX-$thickness,
                         'y' => $centerY+$length,],
                        ['x' => $centerX-$thickness,
                         'y' => $centerY-$length,],
                    ],
                ];
                break;
            case "thermal":
                $diameter = $this->handleArith($point["outer diameter"], $modifiers);
                $centerX = $this->handleArith($point["centerX"], $modifiers);
                $centerY = $this->handleArith($point["centerY"], $modifiers);
                
                $shapes[] = [
                    "type" => "circle",
                    "radius"=> $diameter/2,
                    "x" => $centerX,
                    "y" => $centerY,
                ];
                break;
            }
        }

        return $shapes;
    }

    private function handleArith(&$string, &$vars)
    {
        $parsed = $this->arithParse($string);
        $pos = 0;
        return $this->arithCalc($parsed, $vars, strlen($parsed), $pos);
    }
    
    private function arithParse($expression)
    {
        $expression = str_replace("(", "((", $expression);
        $expression = str_replace(")", "))", $expression);
        $expression = str_replace("+", "))+((", $expression);
        $expression = str_replace("-", "))-((", $expression);
        $expression = str_replace("x", ")x(", $expression);
        $expression = str_replace("X", ")x(", $expression);
        $expression = str_replace("/", ")/(", $expression);
        $expression = "((".$expression."))";

        return $expression;
    }

    private function arithCalc(&$string, &$vars, $len, &$pos)
    {
        $val = null;
        for($i = $pos; $i < $len; $i++)
        {
            if($string[$i] === "(")
            {
                $pos = $i+1;
                $val = $this->arithCalc($string, $vars, $len, $pos);
                $i = $pos;
                continue;
            }
        
            if($string[$i] === ")")
            {
                if($val === null)
                {
                    $substr = substr($string, $pos, $i);
                    if($substr[0] === "$")
                    {
                        $substr = substr($substr, 1);
                        sscanf($substr, "%g", $num);
                        $val = $vars[$num-1] ?? 0;
                    }
                    else
                    {
                        sscanf($substr, "%g", $val);
                    }
                }
                $pos = $i;
                return $val;
            }

            if($string[$i] === "+")
            {
                $pos = $i+2;
                $op = $this->arithCalc($string, $vars, $len, $pos);
                $i = $pos;
                $val = ($val + $op);
                continue;
            }

            if($string[$i] === "-")
            {
                $pos = $i+2;
                $op = $this->arithCalc($string, $vars, $len, $pos);
                $i = $pos;
                $val = ($val - $op);
                continue;
            }

            if($string[$i] === "x" || $string[$i] === "X")
            {
                $pos = $i+2;
                $op = $this->arithCalc($string, $vars, $len, $pos);
                $i = $pos;
                $val = ($val * $op);
                continue;
            }

            if($string[$i] === "/")
            {
                $pos = $i+2;
                $op = $this->arithCalc($string, $vars, $len, $pos);
                $i = $pos;
                $val = ($val / $op);
                continue;
            }
        
        }
        return $val;
    }

    private function handleInterpolation(&$command, &$frame)
    {
        $frame["interpolation"] = $command["mode"];
        return ["action" => "continue"];
    }

    private function handleInterpolationMode(&$command, &$frame)
    {
        $frame["interpolation mode"] = $command["mode"];
        return ["action" => "continue"];
    }

    private function handleUnits(&$command)
    {
        $this->units = $command["units"];
        return ["action" => "continue"];
    }
    
    private function handleMove(&$command)
    {
        $move = array();
        if($command["x"] !== null)
        {
            $move["x"] = $command["x"];
        }

        if($command["y"] !== null)
        {
            $move["y"] = $command["y"];    
        }
        return ["action" => "apply",
                "move" => $move];
    }

    private function handleDraw(&$command, &$frame)
    {
        $move = array();
        
        $minX = $frame["aperiture"]["minX"];
        $maxX = $frame["aperiture"]["maxX"];
        $minY = $frame["aperiture"]["minY"];
        $maxY = $frame["aperiture"]["maxY"];

        if($frame["interpolation"] === "linear")
        {
            if($command["x"] !== null)
            {           
                $move["x"] = $command["x"];

                if($maxX < $move["x"] - $frame["posX"] + $frame["aperiture"]["maxX"])
                    $maxX =  $move["x"] - $frame["posX"] + $frame["aperiture"]["maxX"];

                if($minX > $move["x"] - $frame["posX"] + $frame["aperiture"]["minX"])
                    $minX = $move["x"] - $frame["posX"] + $frame["aperiture"]["minX"];
            }

            if($command["y"] !== null)
            {           
                $move["y"] = $command["y"];

                if($maxY < $move["y"] - $frame["posY"] + $frame["aperiture"]["maxY"])
                    $maxY = $move["y"] - $frame["posY"] + $frame["aperiture"]["maxY"];

                if($minY > $move["y"] - $frame["posY"] + $frame["aperiture"]["minY"])
                    $minY = $move["y"] - $frame["posY"]  + $frame["aperiture"]["minY"];
            }
        }
        else if($frame["interpolation"] === "circular clockwise")
        {
            $start = ['x' => $frame['posX'],
                      'y' => $frame['posY']];
            $center = ['x' => $frame['posX']+$command['i'],
                       'y' => $frame['posY']+$command['j']];
            $end = ['x' => $command['x'],
                    'y' => $command['y']];

            $radius = sqrt(($start['x']-$center['x'])**2 + ($start['y']-$center['y'])**2);

            $points[] = $start;
            $points[] = $end;

            $startAngle = $this->getAngle($center['x'], $center['y'], $start['x'], $start['y']);
            $endAngle = $this->getAngle($center['x'], $center['y'], $end['x'], $end['y']);

            if($startAngle == $endAngle)
            {
                if(true)
                {
                    $startAngle = 0;
                    $endAngle = 2 * M_PI;
                    $points[] = ['x' => $center['x']+$radius,
                                 'y' => $center['y'],];
                }
            }
                
            if($startAngle < $endAngle)
            {
                $startAngle = $startAngle + 2 * M_PI;
            }

            //point at 0 angle
            if(2 * M_PI > $endAngle && 2 * M_PI < $startAngle)
            {
                $points[] = ['x' => $center['x']+$radius,
                             'y' => $center['y'],];
            }

            //point at 90 angle
            if(M_PI_2 > $endAngle && M_PI_2 < $startAngle)
            {
                $points[] = ['x' => $center['x'],
                             'y' => $center['y']+$radius,];
            }

            //point at 180 angle
            if(M_PI > $endAngle && M_PI < $startAngle)
            {
                $points[] = ['x' => $center['x']-$radius,
                             'y' => $center['y'],];
            }

            //point at 270 angle
            if(M_PI + M_PI_2 > $endAngle && M_PI + M_PI_2 < $startAngle)
            {
                $points[] = ['x' => $center['x'],
                             'y' => $center['y']-$radius];
            }
            
            foreach($points as $point)
            {
                if($maxX < $point["x"] - $frame["posX"] + $frame["aperiture"]["maxX"])
                    $maxX =  $point["x"] - $frame["posX"] + $frame["aperiture"]["maxX"];

                if($minX > $point["x"] - $frame["posX"] + $frame["aperiture"]["minX"])
                    $minX = $point["x"] - $frame["posX"] + $frame["aperiture"]["minX"];

                if($maxY < $point["y"] - $frame["posY"] + $frame["aperiture"]["maxY"])
                    $maxY = $point["y"] - $frame["posY"] + $frame["aperiture"]["maxY"];

                if($minY > $point["y"] - $frame["posY"] + $frame["aperiture"]["minY"])
                    $minY = $point["y"] - $frame["posY"]  + $frame["aperiture"]["minY"];
            }
        }
        else if ($frame["interpolation"] === "circular counterclockwise")
        {
            $start = ['x' => $frame['posX'],
                      'y' => $frame['posY']];
            $center = ['x' => $frame['posX']+$command['i'],
                       'y' => $frame['posY']+$command['j']];
            $end = ['x' => $command['x'],
                    'y' => $command['y']];

            $radius = sqrt(($start['x']-$center['x'])**2 + ($start['y']-$center['y'])**2);

            $points[] = $start;
            $points[] = $end;

            $startAngle = $this->getAngle($center['x'], $center['y'], $start['x'], $start['y']);
            $endAngle = $this->getAngle($center['x'], $center['y'], $end['x'], $end['y']);

            if($startAngle == $endAngle)
            {
                if(true)
                {
                    $startAngle = 0;
                    $endAngle = 2 * M_PI;
                    $points[] = ['x' => $center['x']+$radius,
                                 'y' => $center['y'],];
                }
            }
            
            if($endAngle < $startAngle)
            {
                $endAngle = $endAngle + 2 * M_PI;
            }

            //point at 0 angle
            if(2 * M_PI < $endAngle && 2 * M_PI > $startAngle)
            {
                $points[] = ['x' => $center['x']+$radius,
                             'y' => $center['y'],];
            }

            //point at 90 angle
            if(M_PI_2 < $endAngle && M_PI_2 > $startAngle)
            {
                $points[] = ['x' => $center['x'],
                             'y' => $center['y']+$radius,];
            }

            //point at 180 angle
            if(M_PI < $endAngle && M_PI > $startAngle)
            {
                $points[] = ['x' => $center['x']-$radius,
                             'y' => $center['y'],];
            }

            //point at 270 angle
            if(M_PI + M_PI_2 < $endAngle && M_PI + M_PI_2 > $startAngle)
            {
                $points[] = ['x' => $center['x'],
                             'y' => $center['y']-$radius];
            }
            
            foreach($points as $point)
            {
                if($maxX < $point["x"] - $frame["posX"] + $frame["aperiture"]["maxX"])
                    $maxX =  $point["x"] - $frame["posX"] + $frame["aperiture"]["maxX"];

                if($minX > $point["x"] - $frame["posX"] + $frame["aperiture"]["minX"])
                    $minX = $point["x"] - $frame["posX"] + $frame["aperiture"]["minX"];

                if($maxY < $point["y"] - $frame["posY"] + $frame["aperiture"]["maxY"])
                    $maxY = $point["y"] - $frame["posY"] + $frame["aperiture"]["maxY"];

                if($minY > $point["y"] - $frame["posY"] + $frame["aperiture"]["minY"])
                    $minY = $point["y"] - $frame["posY"]  + $frame["aperiture"]["minY"];
            }
            
        }
        else
        {
            error_log("unknown interpolation mode");
        }

        
        return ["action" => "apply",
                "size" => ["minX" => $minX,
                           "maxX" => $maxX,
                           "minY" => $minY,
                           "maxY" => $maxY],
                "move" => $move];
    }

    //returns angle of point in radians in relation to circle center
    private function getAngle($centerX, $centerY, $x, $y)
    {
        return atan2($y-$centerY, $x-$centerX);
    }
    
    private function handleFlash(&$command, &$frame)
    {
        $move = array();
        
        if($command["x"] !== null)
        {
            $frame["posX"] = $command["x"];
            $move["x"] = $command["x"];
        }

        if($command["y"] !== null)
        {
            $frame["posY"] = $command["y"];
            $move["y"] = $command["y"];    
        }

        return ["action" => "apply",
                "move" => $move,
                "size" => [
                    "minX" => $frame["aperiture"]["minX"],
                    "maxX" => $frame["aperiture"]["maxX"],
                    "minY" => $frame["aperiture"]["minY"],
                    "maxY" => $frame["aperiture"]["maxY"]
                ]
        ];
    }

    private function handleRepeat(&$command, &$frame)
    {
        $localFrame = $this->createNewFrame();
        $localFrame["aperiture"] = $frame["aperiture"];
        $localFrame["rotation"] = $frame["rotation"];
        
        foreach($command["shape"] as &$subCommand)
        {
            $subAction = $this->processCommand($subCommand, $localFrame);
            if($subAction["action"] == "apply")
            {
                $this->processApplyAction($subAction, $localFrame);
            }
        }

        if($localFrame["minX"] > $localFrame["minX"] + ($command["x"]-1) * $command["i"] || $localFrame["minX"] === null)
            $localFrame["minX"] = $localFrame["minX"] + ($command["x"]-1) * $command["i"];

        if($localFrame["maxX"] < $localFrame["maxX"] + ($command["x"]-1) * $command["i"] || $localFrame["maxX"] === null)
            $localFrame["maxX"] = $localFrame["maxX"] + ($command["x"]-1) * $command["i"];

        if($localFrame["minY"] > $localFrame["minY"] + ($command["y"]-1) * $command["j"] || $localFrame["minY"] === null)
            $localFrame["minY"] = $localFrame["minY"] + ($command["y"]-1) * $command["j"];

        if($localFrame["maxY"] < $localFrame["maxY"] + ($command["y"]-1) * $command["j"] || $localFrame["maxY"] === null)
            $localFrame["maxY"] = $localFrame["maxY"] + ($command["y"]-1) * $command["j"];
        
        $size = [
            "minX" => $localFrame["minX"],
            "maxX" => $localFrame["maxX"],
            "minY" => $localFrame["minY"],
            "maxY" => $localFrame["maxY"],
        ];
        
        //The current point is undefined after an SR statement.
        $move = [
            "x" => null,
            "y" => null
        ];

        $frame["aperiture"] = $localFrame["aperiture"];

        return ["action" => "apply",
                "size" => $size,
                "move" => $move]; 
    }

    private function handleRotation(&$command, &$frame)
    {
        $frame["rotation"] = (float)$command["degrees"];
        if($frame["aperiture"] !== null)
        {
            $aperitureCommand = ["type" => "apply aperiture",
                                 "number" => $frame["aperiture"]["dcode"]];
            $this->handleApplyAperiture($aperitureCommand, $frame);
        }
        return ["action" => "continue"];
    }

    private function handleScaling(&$command, &$frame)
    {
        $frame["scaling"] = (float)$command["scale"];
        if($frame["aperiture"] !== null)
        {
            $aperitureCommand = ["type" => "apply aperiture",
                                 "number" => $frame["aperiture"]["dcode"]];
            $this->handleApplyAperiture($aperitureCommand, $frame);
        }
        return ["action" => "continue"];
    }

    private function handleMirror(&$command, &$frame)
    {
        $frame["mirror"] = $command["mirror"];
        if($frame["aperiture"] != null)
        {
            $aperitureCommand = ["type" => "apply aperiture",
                                 "number" => $frame["aperiture"]["dcode"]];
            $this->handleApplyAperiture($aperitureCommand, $frame);
        }
        return ["action" => "continue"]; 
    }
    
    private function rotatePoint(&$point, &$frame)
    {
        $rotation = deg2rad($frame["rotation"]);
        $x = $point['x'];
        $y = $point['y'];

        return ['x' => ($x * cos($rotation) - $y * sin($rotation)),
                'y' => ($y * cos($rotation) + $x * sin($rotation))];
    }
    
    private function pointsToBox(&$shapePoints, &$frame)
    {
        $minX = null;
        $maxX = null;
        $minY = null;
        $maxY = null;
        
        foreach($shapePoints as &$shapePoint)
        {
            $point = $this->rotatePoint($shapePoint, $frame);
            if($point['x'] < $minX || $minX === null)
                $minX = $point['x'];
            if($point['x'] > $maxX || $maxX === null)
                $maxX = $point['x'];
            if($point['y'] < $minY || $minY === null)
                $minY = $point['y'];
            if($point['y'] > $maxY || $maxY === null)
                $maxY = $point['y'];
        }
        return [
            'minX' => $minX*$frame["scaling"],
            'maxX' => $maxX*$frame["scaling"],
            'minY' => $minY*$frame["scaling"],
            'maxY' => $maxY*$frame["scaling"],
        ];
    }

    private function circleToBox(&$circle, &$frame)
    {
        $center = $this->rotatePoint($circle, $frame);
        return [
            'minX' => ($center['x']-$circle["radius"])*$frame["scaling"],
            'maxX' => ($center['x']+$circle["radius"])*$frame["scaling"],
            'minY' => ($center['y']-$circle["radius"])*$frame["scaling"],
            'maxY' => ($center['y']+$circle["radius"])*$frame["scaling"],
        ];
    }

    private function combinationToBox(&$combination, &$frame)
    {
        $minX = null;
        $maxX = null;
        $minY = null;
        $maxY = null;
        foreach($combination["sub shapes"] as $subShape)
        {
            $size = $this->determineSize($subShape, $frame);

            if($size['minX'] < $minX || $minX === null)
                $minX = $size['minX'];
            if($size['maxX'] > $maxX || $maxX === null)
                $maxX = $size['maxX'];
            if($size['minY'] < $minY || $minY === null)
                $minY = $size['minY'];
            if($size['maxY'] > $maxY || $maxY === null)
                $maxY = $size['maxY'];
        }
        return[
            'minX' => $minX*$frame["scaling"],
            'maxX' => $maxX*$frame["scaling"],
            'minY' => $minY*$frame["scaling"],
            'maxY' => $maxY*$frame["scaling"],
        ];
    }
    private function blockToBox(&$shape, &$frame)
    {

        $localFrame = $this->createNewFrame();
        $localFrame["aperiture"] = $frame["aperiture"];
        $localFrame["rotation"] = $frame["rotation"];

        foreach($shape["shape"] as &$subCommand)
        {

            if($subCommand["type"] == "draw" || $subCommand["type"] == "move" || $subCommand["type"] == "flash")
            {
                if($subCommand['x'] === null || $subCommand['y'] === null)
                {
                    $localFrame["rotation"] = -$localFrame["rotation"];
                    $point = ['x' => $localFrame['posX'],
                              'y' => $localFrame['posY'],];
                    $rotation = $this->rotatePoint($point, $localFrame);
                    if($subCommand['x'] === null)
                        $subCommand['x'] = $rotation['x'];
                    if($subCommand['y'] === null)
                        $subCommand['y'] = $rotation['y'];
                    $localFrame["rotation"] = -$localFrame["rotation"];
                }
                
                //rotate x and y
                $point = ['x' => $subCommand['x'],
                          'y' => $subCommand['y'],];

                $rotation = $this->rotatePoint($point, $localFrame);
                $subCommand['x'] = $rotation['x'];
                $subCommand['y'] = $rotation['y'];

                //rotate i and j
                $point = ['x' => $subCommand['i'],
                          'y' => $subCommand['j'],];
                $rotation = $this->rotatePoint($point, $localFrame);
                $subCommand['i'] = $rotation['x'];
                $subCommand['j'] = $rotation['y'];

                $centerX = $localFrame['posX']+$subCommand['i'];
                $centerY = $localFrame['posY']+$subCommand['j'];
                
            }

            $action = $this->processCommand($subCommand, $localFrame);
            if($action["action"] == "apply")
            {
                $this->processApplyAction($action, $localFrame);;
            }
            else if($action["action"] == "continue")
            {
                continue;
            }
            else if($action["action"] == "end")
            {
                break;
            }
            else
            {
                error_log("unhandled action in blockToBox: action ".print_r($action, true)." command". print_r($subCommand, true)."\n");
            }
        }
        
        return[
            'minX' => $localFrame["minX"]*$frame["scaling"],
            'maxX' => $localFrame["maxX"]*$frame["scaling"],
            'minY' => $localFrame["minY"]*$frame["scaling"],
            'maxY' => $localFrame["maxY"]*$frame["scaling"],
        ];
    }
    private function determineSize(&$shape, &$frame)
    {
        switch ($shape["type"])
        {
        case "circle":
            return $this->circleToBox($shape, $frame);
            break;
        case "polygon":
            return $this->pointsToBox($shape["points"], $frame);
            break;
        case "combination":
            return $this->combinationToBox($shape, $frame);
            break;
        case "block":
            return $this->blockToBox($shape, $frame);
            break;
        }
        return false;
    }
}
?>
