<?php
class tokenizer{
    private $file;
    private $attribute = false;
    
    public function __construct($filename)
    {
        $this->file = fopen($filename, "r");
    }

    public function __destruct()
    {
        fclose($this->file);
    }

    public function read()
    {
        if(feof($this->file))
        {
            //return false to indicate that there is no characters left to parse
            return false;
        }
        
        $start = ftell($this->file);
        while(false !== ($char = fgetc($this->file)))
        {
            if($char == '*')
            {
                $end = ftell($this->file);
                fseek($this->file, $start);
                $command = fread($this->file, $end-$start-1);
                fseek($this->file, 1, SEEK_CUR);
                return ['type' => "command", 'command' => trim($command)];
            }
            if($char == '%')
            {
                if($this->attribute == false)
                {
                    $this->attribute = true;
                    return ['type' => "attribute start"];
                }
                else
                {
                    $this->attribute = false;
                    return ['type' => "attribute end"];
                }
            }
        }
        
        if($char === false){
            return false;
        }

        return true;
    }
}
?>
