<?php

/**
 * Created by PhpStorm.
 * User: kmarasinska
 * Date: 02/09/15
 * Time: 11:33 AM
 */
abstract class FileWriter
{
    /**
     * Path and name of file to be written
     *
     * @var string path and file name
     */
    private $fileName;

    /**
     * File content line by line
     *
     * @var array containing lines of file content
     */
    private $fileContent;

    /**
     * File pointer resource
     *
     * @var resource
     */
    private $filePointer;

    /**
     * FileWriter constructor.
     */
    public function __construct($fileName)
    {
        $this->fileName = $fileName;
    }

    public function openFile() {
        $this->filePointer = fopen($this->fileName, "w");
        if (!$this->filePointer) {
            echo "\n ERROR: Failed opening file ".$this->fileName."\n";
            return false;
        }

        return true;
    }

    public function closeFile(){
        fclose($this->filePointer);
    }

}