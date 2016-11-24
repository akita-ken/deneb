<?php
require 'vendor/autoload.php';

use Gregwar\Image\Image;

class ImageTwig extends Twig_Extension
{
    public function getName()
    {
        return 'image';
    }

    public function getFunctions()
    {
        return array(
            new Twig_SimpleFunction('image', array($this, 'image'))
        );
    }

    public function image($path)
    {
        return Image::open($path);
    }
}

