<?php
/*
 * You may not change or alter any portion of this comment or credits
 * of supporting developers from this source code or any supporting source code
 * which is considered copyrighted (c) material of the original comment or credit authors.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 */

/**
 * @copyright    XOOPS Project http://xoops.org/
 * @license      GNU GPL 2 or later (https://www.gnu.org/licenses/gpl-2.0.html)
 * @package
 * @since
 * @author       XOOPS Development Team, Kazumi Ono (AKA onokazu)
 */

defined('XOOPS_ROOT_PATH') || exit('XOOPS root path not defined');
require_once XOOPS_ROOT_PATH . '/class/xml/saxparser.php';
require_once XOOPS_ROOT_PATH . '/class/xml/xmltaghandler.php';

/**
 * Class RSS Parser
 *
 * This class offers methods to parse RSS Files
 *
 * @link          http://www.xoops.org/ Latest release of this class
 * @package       class
 * @copyright (c) 2000-2016 XOOPS Project (www.xoops.org)
 * @author        Kazumi Ono <onokazu@xoops.org>
 * @access        public
 */
class XoopsXmlRpcParser extends SaxParser
{
    /**
     * @access private
     * @var array
     */
    public $_param;

    /**
     * @access private
     * @var string
     */
    public $_methodName;

    /**
     * @access private
     * @var array
     */
    public $_tempName;

    /**
     * @access private
     * @var mixed
     */
    public $_tempValue;

    /**
     * @access private
     * @var array
     */
    public $_tempMember;

    /**
     * @access private
     * @var array
     */
    public $_tempStruct;

    /**
     * @access private
     * @var array
     */
    public $_tempArray;

    /**
     * @access private
     * @var array
     */
    public $_workingLevel = array();

    /**
     * Constructor of the class
     * @access
     * @author
     * @see
     * @param string $input
     */
    public function __construct($input)
    {
        parent::__construct($input);
        $this->addTagHandler(new RpcMethodNameHandler());
        $this->addTagHandler(new RpcIntHandler());
        $this->addTagHandler(new RpcDoubleHandler());
        $this->addTagHandler(new RpcBooleanHandler());
        $this->addTagHandler(new RpcStringHandler());
        $this->addTagHandler(new RpcDateTimeHandler());
        $this->addTagHandler(new RpcBase64Handler());
        $this->addTagHandler(new RpcNameHandler());
        $this->addTagHandler(new RpcValueHandler());
        $this->addTagHandler(new RpcMemberHandler());
        $this->addTagHandler(new RpcStructHandler());
        $this->addTagHandler(new RpcArrayHandler());
    }

    /**
     * This Method starts the parsing of the specified RDF File. The File can be a local or a remote File.
     *
     * @param string $name
     *
     * @return void
     */
    public function setTempName($name)
    {
        $this->_tempName[$this->getWorkingLevel()] = $name;
    }

    /**
     * @return mixed
     */
    public function getTempName()
    {
        return $this->_tempName[$this->getWorkingLevel()];
    }

    /**
     * @param mixed $value
     */
    public function setTempValue($value)
    {
        if (is_array($value)) {
            $this->_tempValue = (array)$this->_tempValue;
            foreach ($value as $k => $v) {
                $this->_tempValue[$k] = $v;
            }
        } elseif (is_string($value)) {
            if (!empty($this->_tempValue)) {
                if (is_string($this->_tempValue)) {
                    $this->_tempValue .= $value;
                }
            } else {
                $this->_tempValue = $value;
            }
        } else {
            $this->_tempValue = $value;
        }
    }

    /**
     * @return mixed
     */
    public function getTempValue()
    {
        return $this->_tempValue;
    }

    /**
     * @return void
     */
    public function resetTempValue()
    {
        unset($this->_tempValue);
    }

    /**
     * @param string $name
     * @param string $value
     */
    public function setTempMember($name, $value)
    {
        $this->_tempMember[$this->getWorkingLevel()][$name] = $value;
    }

    /**
     * @return mixed
     */
    public function getTempMember()
    {
        return $this->_tempMember[$this->getWorkingLevel()];
    }

    /**
     * @return void
     */
    public function resetTempMember()
    {
        $this->_tempMember[$this->getCurrentLevel()] = array();
    }

    /**
     * @return void
     */
    public function setWorkingLevel()
    {
        $this->_workingLevel[] = $this->getCurrentLevel();
    }

    /**
     * @return mixed
     */
    public function getWorkingLevel()
    {
        return $this->_workingLevel[count($this->_workingLevel) - 1];
    }

    /**
     * @return void
     */
    public function releaseWorkingLevel()
    {
        array_pop($this->_workingLevel);
    }

    /**
     * @param array $member
     */
    public function setTempStruct($member)
    {
        $key                                               = key($member);
        $this->_tempStruct[$this->getWorkingLevel()][$key] = $member[$key];
    }

    /**
     * @return mixed
     */
    public function getTempStruct()
    {
        return $this->_tempStruct[$this->getWorkingLevel()];
    }

    /**
     * @return void
     */
    public function resetTempStruct()
    {
        $this->_tempStruct[$this->getCurrentLevel()] = array();
    }

    /**
     * @param string $value
     */
    public function setTempArray($value)
    {
        $this->_tempArray[$this->getWorkingLevel()][] = $value;
    }

    /**
     * @return mixed
     */
    public function getTempArray()
    {
        return $this->_tempArray[$this->getWorkingLevel()];
    }

    /**
     * @return void
     */
    public function resetTempArray()
    {
        $this->_tempArray[$this->getCurrentLevel()] = array();
    }

    /**
     * @param string $methodName
     */
    public function setMethodName($methodName)
    {
        $this->_methodName = $methodName;
    }

    /**
     * @return string
     */
    public function getMethodName()
    {
        return $this->_methodName;
    }

    /**
     * @param string $value
     */
    public function setParam($value)
    {
        $this->_param[] = $value;
    }

    /**
     * @return array
     */
    public function getParam()
    {
        return $this->_param;
    }
}

/**
 * Class RpcMethodNameHandler
 */
class RpcMethodNameHandler extends XmlTagHandler
{
    /**
     * @return string
     */
    public function getName()
    {
        return 'methodName';
    }

    /**
     * @param \XoopsXmlRpcParser $parser
     * @param string $data
     */
    public function handleCharacterData($parser, &$data)
    {
        $parser->setMethodName($data);
    }
}

/**
 * Class RpcIntHandler
 */
class RpcIntHandler extends XmlTagHandler
{
    /**
     * @return array
     */
    public function getName()
    {
        return array(
            'int',
            'i4',
        );
    }

    /**
     * @param \XoopsXmlRpcParser $parser
     * @param  string $data
     */
    public function handleCharacterData($parser, &$data)
    {
        $parser->setTempValue((int)$data);
    }
}

/**
 * Class RpcDoubleHandler
 */
class RpcDoubleHandler extends XmlTagHandler
{
    /**
     * @return string
     */
    public function getName()
    {
        return 'double';
    }

    /**
     * @param \XoopsXmlRpcParser $parser
     * @param  string $data
     */
    public function handleCharacterData($parser, &$data)
    {
        $data = (float)$data;
        $parser->setTempValue($data);
    }
}

/**
 * Class RpcBooleanHandler
 */
class RpcBooleanHandler extends XmlTagHandler
{
    /**
     * @return string
     */
    public function getName()
    {
        return 'boolean';
    }

    /**
     * @param \XoopsXmlRpcParser $parser
     * @param  string $data
     */
    public function handleCharacterData($parser, &$data)
    {
        $data = (boolean)$data;
        $parser->setTempValue($data);
    }
}

/**
 * Class RpcStringHandler
 */
class RpcStringHandler extends XmlTagHandler
{
    /**
     * @return string
     */
    public function getName()
    {
        return 'string';
    }

    /**
     * @param \XoopsXmlRpcParser $parser
     * @param  string $data
     */
    public function handleCharacterData($parser, &$data)
    {
        $parser->setTempValue((string)$data);
    }
}

/**
 * Class RpcDateTimeHandler
 */
class RpcDateTimeHandler extends XmlTagHandler
{
    /**
     * @return string
     */
    public function getName()
    {
        return 'dateTime.iso8601';
    }

    /**
     * @param \XoopsXmlRpcParser $parser
     * @param  string $data
     */
    public function handleCharacterData($parser, &$data)
    {
        $matches = array();
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2}):(\d{2}):(\d{2})$/', $data, $matches)) {
            $parser->setTempValue(time());
        } else {
            $parser->setTempValue(gmmktime($matches[4], $matches[5], $matches[6], $matches[2], $matches[3], $matches[1]));
        }
    }
}

/**
 * Class RpcBase64Handler
 */
class RpcBase64Handler extends XmlTagHandler
{
    /**
     * @return string
     */
    public function getName()
    {
        return 'base64';
    }

    /**
     * @param \XoopsXmlRpcParser $parser
     * @param  string $data
     */
    public function handleCharacterData($parser, &$data)
    {
        $parser->setTempValue(base64_decode($data));
    }
}

/**
 * Class RpcNameHandler
 */
class RpcNameHandler extends XmlTagHandler
{
    /**
     * @return string
     */
    public function getName()
    {
        return 'name';
    }

    /**
     * @param \XoopsXmlRpcParser $parser
     * @param  string $data
     */
    public function handleCharacterData($parser, &$data)
    {
        switch ($parser->getParentTag()) {
            case 'member':
                $parser->setTempName($data);
                break;
            default:
                break;
        }
    }
}

/**
 * Class RpcValueHandler
 */
class RpcValueHandler extends XmlTagHandler
{
    /**
     * @return string
     */
    public function getName()
    {
        return 'value';
    }

    /**
     * @param \XoopsXmlRpcParser $parser
     * @param  string $data
     */
    public function handleCharacterData($parser, &$data)
    {
        switch ($parser->getParentTag()) {
            case 'member':
                $parser->setTempValue($data);
                break;
            case 'data':
            case 'array':
                $parser->setTempValue($data);
                break;
            default:
                break;
        }
    }

    /**
     * @param \XoopsXmlRpcParser $parser
     * @param array $attributes
     */
    public function handleBeginElement($parser, &$attributes)
    {
        //$parser->resetTempValue();
    }

    /**
     * @param \XoopsXmlRpcParser $parser
     */
    public function handleEndElement($parser)
    {
        switch ($parser->getCurrentTag()) {
            case 'member':
                $parser->setTempMember($parser->getTempName(), $parser->getTempValue());
                break;
            case 'array':
            case 'data':
                $parser->setTempArray($parser->getTempValue());
                break;
            default:
                $parser->setParam($parser->getTempValue());
                break;
        }
        $parser->resetTempValue();
    }
}

/**
 * Class RpcMemberHandler
 */
class RpcMemberHandler extends XmlTagHandler
{
    /**
     * @return string
     */
    public function getName()
    {
        return 'member';
    }

    /**
     * @param \XoopsXmlRpcParser $parser
     * @param array $attributes
     */
    public function handleBeginElement($parser, &$attributes)
    {
        $parser->setWorkingLevel();
        $parser->resetTempMember();
    }

    /**
     * @param \XoopsXmlRpcParser $parser
     */
    public function handleEndElement($parser)
    {
        $member = $parser->getTempMember();
        $parser->releaseWorkingLevel();
        $parser->setTempStruct($member);
    }
}

/**
 * Class RpcArrayHandler
 */
class RpcArrayHandler extends XmlTagHandler
{
    /**
     * @return string
     */
    public function getName()
    {
        return 'array';
    }

    /**
     * @param \XoopsXmlRpcParser $parser
     * @param array $attributes
     */
    public function handleBeginElement($parser, &$attributes)
    {
        $parser->setWorkingLevel();
        $parser->resetTempArray();
    }

    /**
     * @param \XoopsXmlRpcParser $parser
     */
    public function handleEndElement($parser)
    {
        $parser->setTempValue($parser->getTempArray());
        $parser->releaseWorkingLevel();
    }
}

/**
 * Class RpcStructHandler
 */
class RpcStructHandler extends XmlTagHandler
{
    /**
     * @return string
     */
    public function getName()
    {
        return 'struct';
    }

    /**
     * @param \XoopsXmlRpcParser $parser
     * @param array $attributes
     */
    public function handleBeginElement($parser, &$attributes)
    {
        $parser->setWorkingLevel();
        $parser->resetTempStruct();
    }

    /**
     * @param \XoopsXmlRpcParser $parser
     */
    public function handleEndElement($parser)
    {
        $parser->setTempValue($parser->getTempStruct());
        $parser->releaseWorkingLevel();
    }
}
