<?php
// 
/*******************************************************************************
 * Location: <strong>xml/XmlTagHandler</strong><br>
 * <br>
 * XmlTagHandler<br>
 * <br>
 * Copyright &copy; 2001 eXtremePHP.  All rights reserved.<br>
 * <br>
 * @author Ken Egervari, Remi Michalski<br>
 *******************************************************************************/

class XmlTagHandler
{
    public function __construct()
    {
    }

    /**
     * @return string
     */
    public function getName()
    {
        return '';
    }

    /**
     * @param SaxParser $parser
     * @param array $attributes
     */
    public function handleBeginElement($parser, &$attributes)
    {
    }

    /**
     * @param SaxParser $parser
     */
    public function handleEndElement($parser)
    {
    }

    /**
     * @param SaxParser $parser
     * @param  string $data
     */
    public function handleCharacterData($parser, &$data)
    {
    }
}
