<?php

/**
 * The MetaModels extension allows the creation of multiple collections of custom items,
 * each with its own unique set of selectable attributes, with attribute extendability.
 * The Front-End modules allow you to build powerful listing and filtering of the
 * data in each collection.
 *
 * PHP version 5
 *
 * @package    MetaModels
 * @subpackage Update
 * @author     Stefan Heimes <stefan_heimes@hotmail.com>
 * @copyright  The MetaModels team.
 * @license    LGPL.
 * @filesource
 */

use MetaModels\Attribute\IAttribute;
use MetaModels\Helper\TableManipulation;
use MetaModels\IMetaModel;

/**
 * Initialize the system
 */
define('TL_MODE', 'Updater');
require 'system/initialize.php';

/**
 * Class MetaModelsFileUpdater
 *
 * Provides function for updating table columns and function
 * for updating file id to file uuid.
 *
 * Features:
 *    - Search for all Attributes from type file or translatedfile.
 *    - Changed the column type to blob or any other type based on the attribute getSQLDataType.
 *    - Update the tl_metamodel_dca.backicon to binary and all data to uuid. (ATM not active.)
 *    - Check if the columns already up to date.
 *    - Check if the current installation has old versions of file or translatedfile.
 */
class MetaModelsFileUpdater extends \Backend
{
	/**
	 * Config array.
	 *
	 * @var array
	 */
	protected $arrConfig = array();

	/**
	 * List with all MetaModels.
	 *
	 * @var array
	 */
	protected $arrMetaModels = array();

	/**
	 * List with attributes for the update. array([MM Name] => array([Attribute1], [Attribute2]))
	 *
	 * @var array
	 */
	protected $arrAttributes = array();

	/**
	 * A list with metamodels that should ignored.
	 *
	 * @var array
	 */
	protected $arrBlacklistMetaModels = array();

	/**
	 * Message for output.
	 *
	 * @var array
	 */
	protected $arrMessages = array();

	/**
	 * Construct.
	 */
	public function __construct()
	{
		parent::__construct();

		$this->intiConfig();
	}

	/**
	 * Init the config array.
	 */
	protected function intiConfig()
	{
		// Set the attributes for update.
		$this->arrConfig['allowed_attributes'] = array(
			'file',
			'translatedfile'
		);

		// Mapping for complex attributes to table.
		$this->arrConfig['attribute_table_mapping'] = array(
			'translatedfile' => 'tl_metamodel_translatedlongblob'
		);
	}

	/**
	 * Add a log msg to the contao log and to the msg array.
	 *
	 * @param string $strMsg      The message.
	 *
	 * @param string $strFunction Name of the function.
	 *
	 * @param string $strCategory Category of the message.
	 */
	protected function addLogMsg($strMsg, $strFunction, $strCategory)
	{
		// Add to contao log.
		$this->log($strMsg, __CLASS__ . '::' . $strFunction, $strCategory);

		// Add to the locale msg array.
		$this->arrMessages[] = $strCategory . ':  ' . $strMsg . '  (' . $strFunction . ')';
	}

	/**
	 * Add a MetaModels to the blacklist.
	 *
	 * @param string $strName Name of an metamodels
	 */
	public function addIgnoredMetaModels($strName)
	{
		$this->arrBlacklistMetaModels[$strName] = true;
	}

	/**
	 * Get a list with all messages.
	 *
	 * @return array A list of all messages
	 */
	public function getMessagesLogs()
	{
		return $this->arrMessages;
	}

	/**
	 * Try to load the mm by id or name.
	 *
	 * @param mixed $nameOrId Name or id of mm.
	 *
	 * @return IMetaModel
	 */
	protected function getMetaModels($nameOrId)
	{
		// ID.
		if (is_numeric($nameOrId))
		{
			return \MetaModels\Factory::byId($nameOrId);
		}
		// Name.
		elseif (is_string($nameOrId))
		{
			return \MetaModels\Factory::byTableName($nameOrId);
		}

		// Unknown.
		return null;
	}

	/**
	 * Get for an attribute type the value table.
	 *
	 * @param string $strAttributeType Attribute type.
	 *
	 * @return string|null Return the name of table or null.
	 */
	protected function getTableForAttributeType($strAttributeType)
	{
		if (array_key_exists($strAttributeType, $this->arrConfig['attribute_table_mapping']))
		{
			return $this->arrConfig['attribute_table_mapping'][$strAttributeType];
		}

		return null;
	}

	/**
	 * Get a list with all MetaModels.
	 */
	protected function getAllMetaModels()
	{
		$arrMetaModels = \MetaModels\Factory::getAllTables();

		foreach ($arrMetaModels as $strName)
		{
			if (!array_key_exists($strName, $this->arrBlacklistMetaModels))
			{
				$this->arrMetaModels[] = $strName;
			}
		}

	}

	/**
	 * Get a list with all Attributes based on the MetaModel list.
	 * Filter this list on the config allowed_attributes.
	 */
	protected function getAllFileAttribute()
	{
		foreach ($this->arrMetaModels as $strMetaModelsName)
		{
			$objMetaModels = $this->getMetaModels($strMetaModelsName);
			if ($objMetaModels == null)
			{
				continue;
			}

			$arrAttributes = $objMetaModels->getAttributes();
			foreach ($arrAttributes as $strAttributeName => $objAttribute)
			{
				if (in_array($objAttribute->get('type'), $this->arrConfig['allowed_attributes']))
				{
					$this->arrAttributes[$strMetaModelsName][] = $strAttributeName;
				}
			}
		}
	}


	/**
	 * Check if a database column is from a special type.
	 *
	 * @param string $strTable  Name of table
	 *
	 * @param string $strColumn Name of column
	 *
	 * @param array  $arrTypes  List of types
	 *
	 * @return bool
	 */
	protected function isColumnFromType($strTable, $strColumn, $arrTypes)
	{
		$objDesc = \Database::getInstance()->query("DESC $strTable $strColumn");

		// Change the column type
		if (in_array($objDesc->Type, $arrTypes))
		{
			return true;
		}

		return false;
	}

	/**
	 * Check if the column is already up to date.
	 *
	 * @param string $strTableName Name of the table
	 *
	 * @param string $strColName   Name of the column.
	 *
	 * @return bool True => Up to Date | False => Need Update.
	 */
	protected function isColumnUpToDate($strTableName, $strColName)
	{
		// Second check if the fields already up to date.
		if ($this->isColumnFromType($strTableName, $strColName, array('blob', 'longblob', 'binary')))
		{
			$this->addLogMsg(sprintf('%s.%s seems to be already up to date.', $strTableName, $strColName),
				__FUNCTION__,
				TL_GENERAL
			);
			return true;
		}

		return false;
	}

	/**
	 * Check if the attribute is valid.
	 *
	 * @param IMetaModel $objMetaModels Current MetaModels.
	 *
	 * @param IAttribute $objAttribute  Current attribute.
	 *
	 * @return bool True => Is valid. | False => Not valid.
	 */
	protected function isSqlTypeValid(IMetaModel $objMetaModels, IAttribute $objAttribute)
	{
		if (stripos($objAttribute->getSQLDataType(), 'text') !== false)
		{
			$this->addLogMsg(sprintf('Could not update %s.%s, because the type is %s. It seems you using an older version of MetaModels.',
					$objMetaModels->getTableName(),
					$objAttribute->getColName(),
					$objAttribute->getSQLDataType()
				),
				__FUNCTION__,
				TL_ERRO
			);

			return false;
		}

		return true;
	}

	/**
	 * Check if the table is valid.
	 *
	 * @param \MetaModels\Attribute\IAttribute $objAttribute Attribute for information.
	 *
	 * @param string                           $strTableName Name of table
	 *
	 * @return bool  True => valid | False => invalid
	 */
	protected function isValidTable($objAttribute, $strTableName)
	{
		// Check empty.
		if (empty($strTableName))
		{
			$this->addLogMsg('Unknown data table for ' . $objAttribute->getName() . '[' . $objAttribute->getColName() . '] Table name: ' . $strTableName,
				__FUNCTION__,
				TL_ERROR
			);

			return false;
		}

		// Check if the table exists.
		if (!\Database::getInstance()->tableExists($strTableName))
		{
			$this->addLogMsg('Could not find the data table for ' . $objAttribute->getName() . '[' . $objAttribute->getColName() . ']. Table name: ' . $strTableName,
				__FUNCTION__,
				TL_ERROR
			);

			return false;
		}

		return true;
	}

	/**
	 * Generate a helper object based on a field value
	 *
	 * @param mixed $value The field value
	 *
	 * @return \stdClass The helper object
	 */
	protected function generateHelperObject($value)
	{
		$return = new \stdClass();

		if (!is_array($value))
		{
			$return->value     = rtrim($value, "\x00");
			$return->isUuid    = (strlen($value) == 16 && !is_numeric($return->value) && strncmp($return->value, $GLOBALS['TL_CONFIG']['uploadPath'] . '/', strlen($GLOBALS['TL_CONFIG']['uploadPath']) + 1) !== 0);
			$return->isNumeric = (is_numeric($return->value) && $return->value > 0);
		}
		else
		{
			$return->value     = array_map(function ($var)
				{
					return rtrim($var, "\x00");
				}, $value
			);
			$return->isUuid    = (strlen($value[0]) == 16 && !is_numeric($return->value[0]) && strncmp($return->value[0], $GLOBALS['TL_CONFIG']['uploadPath'] . '/', strlen($GLOBALS['TL_CONFIG']['uploadPath']) + 1) !== 0);
			$return->isNumeric = (is_numeric($return->value[0]) && $return->value[0] > 0);
		}

		return $return;
	}


	/**
	 * Check the Version, if lower than 3.2.x set msg.
	 *
	 * @return bool True => allowed for running | False => Error.
	 */
	protected function checkVersion()
	{
		if (version_compare(VERSION, '3.2', '<'))
		{
			$this->arrMessages[] = 'Only target version 3.2.x is allowed for updating.';
			return false;
		}

		return true;
	}

	/**
	 * Main run functions.
	 */
	public function run()
	{
		// Check the Version.
		if (!$this->checkVersion())
		{
			return;
		}

		// Run.
		$this->getAllMetaModels();
		$this->getAllFileAttribute();

		// Check if we have some attributes.
		if (empty($this->arrAttributes))
		{
			$this->addLogMsg('No attributes found for update', __FUNCTION__, TL_GENERAL);
		}

		// Rewrite table and data.
		$this->updateColumnsAndData();
		$this->updateBackendIcon();

		// Done :).
		$this->addLogMsg('All work done for the MetaModels Updater.', __FUNCTION__, TL_GENERAL);
	}


	/**
	 * Run for each MetaModels and each allowed attribute the update.
	 */
	protected function updateColumnsAndData()
	{
		foreach ($this->arrAttributes as $strMetaModelsName => $arrAttributeNames)
		{
			// Get the MetaModels.
			$objMetaModels = $this->getMetaModels($strMetaModelsName);

			foreach ($arrAttributeNames as $strAttribute)
			{
				// Get the attribute object.
				$objAttribute = $objMetaModels->getAttribute($strAttribute);

				// Get all classes/interfaces. We need this wor the working mode.
				$arrImplClasses = class_implements($objAttribute);

				// Run update for simple attributes.
				if (in_array('MetaModels\Attribute\ISimple', $arrImplClasses))
				{
					$this->updateSimple($objMetaModels, $objAttribute, $objAttribute->get('file_multiple'));
				}
				// Run update for complex attributes.
				elseif (in_array('MetaModels\Attribute\IComplex', $arrImplClasses))
				{
					$this->updateComplex($objMetaModels, $objAttribute, $objAttribute->get('file_multiple'));
				}
				// If we reach this point we have no support for this attribute.
				else
				{
					$this->addLogMsg(
						sprintf('The attribute %s[%s] has no known interface.',
							$objAttribute->getName(),
							$objAttribute->getColName()
						),
						__FUNCTION__,
						TL_ERROR
					);
				}
			}
		}
	}

	protected function updateBackendIcon()
	{
		return;

		// TODO: Move this to the core updater.

//		$objData = \Database::getInstance()
//			->prepare('SELECT id, backendicon FROM tl_metamodel_dca')
//			->execute();
//
//		// Update field.
//		\Database::getInstance()->query("ALTER TABLE `tl_metamodel_dca` CHANGE COLUMN `backendicon` `backendicon` binary(16) NULL");
//
//		$this->msg[] = 'Change tl_metamodel_dca.backendicon to binary(16).';
//
//		$intCount = 0;
//		while ($objData->next())
//		{
//			$mixValue = $objData->backendicon;
//			if (empty($mixValue))
//			{
//				continue;
//			}
//
//			// Search file;
//			$objFile = FilesModel::findByPk($mixValue);
//			if ($objFile == null)
//			{
//				continue;
//			}
//
//			\Database::getInstance()
//				->prepare('UPDATE tl_metamodel_dca SET backendicon=? WHERE id=?')
//				->execute($objFile->uuid, $objData->id);
//
//			$intCount++;
//		}
//
//		$this->msg[] = sprintf('Update %s entry|entries in tl_metamodel_dca.', $intCount);
	}


	/**
	 * Update simple attributes.
	 *
	 * @param IMetaModel $objMetaModels The current MetaModels.
	 *
	 * @param IAttribute $objAttribute  The current Attribute.
	 *
	 * @param boolean    $blnMutliple   Flag if we have a array with data.
	 */
	protected function updateSimple(IMetaModel $objMetaModels, IAttribute $objAttribute, $blnMutliple)
	{
		// Get some information.
		$strTableName = $objMetaModels->getTableName();
		$strColName   = $objAttribute->getColName();

		// Check if the current attribute is up to date.
		if (!$this->isSqlTypeValid($objMetaModels, $objAttribute))
		{
			return;
		}

		// Get all Data.
		$objData = \Database::getInstance()
			->prepare(sprintf('SELECT id,%s FROM %s',
					$strColName,
					$strTableName
				)
			)
			->execute();

		// Check if we have to change the field type.
		if (!$this->isColumnUpToDate($strTableName, $strColName))
		{
			// Change field tpye.
			TableManipulation::renameColumn(
				$strTableName,
				$strColName,
				$strColName,
				$objAttribute->getSQLDataType()
			);

			$this->addLogMsg(sprintf('Change %s.%s to %s.', $strTableName, $strColName, $objAttribute->getSQLDataType()),
				__FUNCTION__,
				TL_GENERAL
			);
		}

		$this->updateData($objData, $strTableName, $strColName, $blnMutliple);
	}

	/**
	 * Update complex attributes.
	 *
	 * @param IMetaModel $objMetaModels The current MetaModels.
	 *
	 * @param IAttribute $objAttribute  The current Attribute.
	 *
	 * @param boolean    $blnMutliple   Flag if we have a array with data.
	 */
	protected function updateComplex(IMetaModel $objMetaModels, IAttribute $objAttribute, $blnMutliple)
	{
		$strTableName   = $this->getTableForAttributeType($objAttribute->get('type'));
		$intAttributeID = $objAttribute->get('id');

		// Check if table is valid.
		if (!$this->isValidTable($objAttribute, $strTableName))
		{
			return;
		}

		// Check if the table has the right type if not end here.
		if (!$this->isColumnUpToDate($strTableName, 'value'))
		{
			$this->addLogMsg(sprintf('Could not update complex data for %s[%s] because value table is not from type blob or binary.',
					$objAttribute->getName(),
					$objAttribute->getColName()
				),
				__FUNCTION__,
				TL_ERROR
			);

			return;
		}

		// Get all Data.
		$objData = \Database::getInstance()
			->prepare(sprintf('SELECT * FROM %s WHERE att_id=?',
					$strTableName
				)
			)
			->execute($intAttributeID);

		$this->updateData($objData, $strTableName, 'value', $blnMutliple);
	}

	/**
	 * @param \Database\Result $objData      All Data from the table.
	 *
	 * @param string           $strTableName Name of the table.
	 *
	 * @param string           $strColName   Name of the column in the database
	 *
	 * @param boolean          $blnMultiple  Flag if we have a array with data.
	 */
	protected function updateData(\Database\Result $objData, $strTableName, $strColName, $blnMultiple)
	{
		$intCount = 0;
		while ($objData->next())
		{
			$mixData = $objData->$strColName;

			// Run each file in the array.
			if ($blnMultiple)
			{
				$arrData = deserialize($mixData, true);
				foreach ($arrData as $strKey => $strValue)
				{
					$arrData[$strKey] = $this->resolveFile($strValue);
				}

				$mixData = serialize($arrData);
			}
			// Run for a single file.
			else
			{
				$mixData = $this->resolveFile($mixData);
			}

			// Update the files.
			\Database::getInstance()
				->prepare(sprintf('UPDATE %s SET %s=? WHERE id=?',
						$strTableName,
						$strColName
					)
				)
				->execute($mixData, $objData->id);

			$intCount++;
		}

		// Add log.
		$this->addLogMsg(sprintf('Update %s entry|entries in %s.', $intCount, $strTableName),
			__FUNCTION__,
			TL_GENERAL
		);
	}

	/**
	 * Get the type and resolve the file.
	 *
	 * @param string $strValue Value for lookup
	 *
	 * @return string UUID of the Path/ID/UUID
	 */
	protected function resolveFile($strValue)
	{
		// Get a helper class.
		$objHelper = $this->generateHelperObject($strValue);

		// UUID already
		if ($objHelper->isUuid)
		{
			return $strValue;
		}

		// Numeric ID to UUID
		if ($objHelper->isNumeric)
		{
			$objFile = \FilesModel::findByPk($objHelper->value);
			return $objFile->uuid;
		}
		// Path to UUID
		else
		{
			$objFile = \FilesModel::findByPath($objHelper->value);
			return $objFile->uuid;
		}
	}
}

// Init.
$objRunner = new MetaModelsFileUpdater();

// Add ignored metamodels here.
//$objRunner->addIgnoredMetaModels('mm_z');

// Run Updater.
$objRunner->run();
?>

<?php foreach ($objRunner->getMessagesLogs() as $strMsg): ?>
	<p>
		<?php echo $strMsg; ?>
	</p>
<?php endforeach; ?>