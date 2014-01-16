<?php

/**
 * The MetaModels extension allows the creation of multiple collections of custom items,
 * each with its own unique set of selectable attributes, with attribute extendability.
 * The Front-End modules allow you to build powerful listing and filtering of the
 * data in each collection.
 *
 * PHP version 5
 * @package    MetaModels
 * @subpackage Update
 * @author     Stefan Heimes <stefan_heimes@hotmail.com>
 * @copyright  The MetaModels team.
 * @license    LGPL.
 * @filesource
 */

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
 *
 * TODO:
 *  - Add support for Contao 2.11.x to 3.2.x
 *  - Add check if we have id, path or already a uuid (Support for contao 2.11 and 3.1)
 *  - Optimize code.
 *  - Add more helper functions.
 *  - Make the check for the version better and standalone.
 *  - Write msg information into the log.
 */
class MetaModelsFileUpdater extends \Backend
{
	/**
	 * List with all MetaModels.
	 *
	 * @var array
	 */
	protected $arrMetaModels;

	/**
	 * A list with metamodels that should ignored.
	 *
	 * @var array
	 */
	protected $arrBlacklistMetaModels = array();

	/**
	 * List with attributes for the update. array([MM Name] => array([Attribute1], [Attribute2]))
	 *
	 * @var array
	 */
	protected $arrAttributes = array();

	/**
	 * Config array.
	 *
	 * @var array
	 */
	protected $arrConfig = array();

	/**
	 * Message for output.
	 *
	 * @var string
	 */
	protected $msg = array();

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

	// -- Getter / Setter ----------------------------------------------------------------------------------------------

	public function addIgnoredMetaModels($strName)
	{
		$this->arrBlacklistMetaModels[$strName] = true;
	}

	public function getOutput()
	{
		return $this->msg;
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

	protected function getTableForAttributeType($strAttributeType)
	{
		if (array_key_exists($strAttributeType, $this->arrConfig['attribute_table_mapping']))
		{
			return $this->arrConfig['attribute_table_mapping'][$strAttributeType];
		}

		return null;
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

	// -- Check functions ----------------------------------------------------------------------------------------------

	/**
	 * Check the Version, if lower than 3.2.x set msg.
	 *
	 * @return bool True => allowed for running | False => Error.
	 */
	protected function checkVersion()
	{
		if (version_compare(VERSION, '3.2', '<'))
		{
			$this->msg[] = 'Only target version 3.2.x is allowed for updating.';
			return false;
		}

		return true;
	}

	// -- Run functions ------------------------------------------------------------------------------------------------

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
			$this->msg[] = 'No attributes found for update';
		}

		// Rewrite table and data.
		$this->rewriteData();
		$this->updateBackendIcon();

		// Done :).
		$this->msg[] = 'Done.';
	}

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

	protected function rewriteData()
	{
		foreach ($this->arrAttributes as $strMetaModelsName => $arrAttributeNames)
		{
			$objMetaModels = $this->getMetaModels($strMetaModelsName);

			foreach ($arrAttributeNames as $strAttribute)
			{
				$objAttribute = $objMetaModels->getAttribute($strAttribute);

				$arrImplClasses = class_implements($objAttribute);

				if (in_array('MetaModels\Attribute\ISimple', $arrImplClasses))
				{
					if ($objAttribute->get('file_multiple'))
					{
						$this->updateMultiFiles($objMetaModels, $objAttribute);
					}
					else
					{
						$this->updateSingleFile($objMetaModels, $objAttribute);
					}
				}
				elseif (in_array('MetaModels\Attribute\IComplex', $arrImplClasses))
				{
					if ($objAttribute->get('file_multiple'))
					{
						$this->updateTranslatedMultiFiles($objMetaModels, $objAttribute);
					}
					else
					{
						$this->updateTranslatedSingleFile($objMetaModels, $objAttribute);
					}
				}
			}
		}
	}

	// -- Update functions ---------------------------------------------------------------------------------------------

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
	 * @param IMetaModel                       $objMetaModels
	 * @param \MetaModels\Attribute\IAttribute $objAttribute
	 */
	protected function updateTranslatedSingleFile($objMetaModels, $objAttribute)
	{
		$strTableName   = $this->getTableForAttributeType($objAttribute->get('type'));
		$intAttributeID = $objAttribute->get('id');

		if ($strTableName == null)
		{
			$msg[] = 'ERROR: Could not find the data table for ' . $objAttribute->getName() . '[' . $objAttribute->getColName() . ']';
			return;
		}

		// Second check if the fields already up to date.
		if (!$this->isColumnFromType($strTableName, 'value', array('blob', 'binary')))
		{
			$this->msg[] = sprintf('Warning: %s.%s is not form tyoe blob or binary.', $strTableName, 'value');
			return;
		}

		// Get all Data.
		$objData = \Database::getInstance()
			->prepare(sprintf('SELECT * FROM %s WHERE att_id=?',
				$strTableName
			))
			->execute($intAttributeID);


		$intCount = 0;
		while ($objData->next())
		{
			// Search file;
			$objFile = FilesModel::findByPk($objData->value);
			if ($objFile == null)
			{
				continue;
			}

			// Update the files.
			\Database::getInstance()
				->prepare(sprintf('UPDATE %s SET value=? WHERE id=?',
					$strTableName
				))
				->execute($objFile->uuid, $objData->id);

			$intCount++;
		}

		$this->msg[] = sprintf('Update %s entry|entries in %s.', $intCount, $strTableName);
	}


	/**
	 * @param IMetaModel                       $objMetaModels
	 * @param \MetaModels\Attribute\IAttribute $objAttribute
	 */
	protected function updateTranslatedMultiFiles($objMetaModels, $objAttribute)
	{
		$strTableName   = $this->getTableForAttributeType($objAttribute->get('type'));
		$intAttributeID = $objAttribute->get('id');

		if ($strTableName == null)
		{
			$msg[] = 'ERROR: Could not find the data table for ' . $objAttribute->getName() . '[' . $objAttribute->getColName() . ']';
			return;
		}

		// Second check if the fields already up to date.
		if (!$this->isColumnFromType($strTableName, 'value', array('blob', 'binary')))
		{
			$this->msg[] = sprintf('Warning: %s.%s is not form tyoe blob or binary.', $strTableName, 'value');
			return;
		}

		// Get all Data.
		$objData = \Database::getInstance()
			->prepare(sprintf('SELECT * FROM %s WHERE att_id=?',
				$strTableName
			))
			->execute($intAttributeID);

		$intCount = 0;
		while ($objData->next())
		{
			$arrData = deserialize($objData->value, true);

			foreach ($arrData as $strKey => $strValue)
			{
				// Search file;
				$objFile = FilesModel::findByPk($strValue);
				if ($objFile == null)
				{
					continue;
				}

				// Replace in old array.
				$arrData[$strKey] = $objFile->uuid;
			}

			// Update the files.
			\Database::getInstance()
				->prepare(sprintf('UPDATE %s SET value=? WHERE id=?',
					$strTableName
				))
				->execute(serialize($arrData), $objData->id);

			$intCount++;
		}

		$this->msg[] = sprintf('Update %s entry|entries in %s.', $intCount, $strTableName);
	}


	/**
	 * @param IMetaModel                       $objMetaModels
	 * @param \MetaModels\Attribute\IAttribute $objAttribute
	 */
	protected function updateSingleFile($objMetaModels, $objAttribute)
	{
		$strTableName = $objMetaModels->getTableName();
		$strColName   = $objAttribute->getColName();

		// Check first if we have an older version of metamodels.
		if (stripos($objAttribute->getSQLDataType(), 'text') !== false)
		{
			$this->msg[] = sprintf('ERROR: Could not update %s.%s, because the type is %s. It seems you are using an older version of MetaModels.',
				$strTableName,
				$strColName,
				$objAttribute->getSQLDataType()
			);
			return;
		}

		// Second check if the fields already up to date.
		if ($this->isColumnFromType($strTableName, $strColName, array('blob', 'binary')))
		{
			$this->msg[] = sprintf('%s.%s seems to be already up to date.', $strTableName, $strColName);
			return;
		}

		// Get all Data.
		$objData = \Database::getInstance()
			->prepare(sprintf('SELECT id,%s FROM %s',
				$strColName,
				$strTableName
			))
			->execute();

		// Change field tpye.
		\MetaModels\Helper\TableManipulation::renameColumn(
			$strTableName,
			$strColName,
			$strColName,
			$objAttribute->getSQLDataType()
		);

		$this->msg[] = sprintf('Change %s.%s to %s.', $strTableName, $strColName, $objAttribute->getSQLDataType());

		$intCount = 0;
		while ($objData->next())
		{
			// Search file;
			$objFile = FilesModel::findByPk($objData->$strColName);
			if ($objFile == null)
			{
				continue;
			}

			// Update the files.
			\Database::getInstance()
				->prepare(sprintf('UPDATE %s SET %s=? WHERE id=?',
					$strTableName,
					$strColName
				))
				->execute($objFile->uuid, $objData->id);

			$intCount++;
		}

		$this->msg[] = sprintf('Update %s entry|entries in %s.', $intCount, $strTableName);
	}

	/**
	 * @param IMetaModel                       $objMetaModels
	 * @param \MetaModels\Attribute\IAttribute $objAttribute
	 */
	protected function updateMultiFiles($objMetaModels, $objAttribute)
	{
		$strTableName = $objMetaModels->getTableName();
		$strColName   = $objAttribute->getColName();

		if (stripos($objAttribute->getSQLDataType(), 'text') !== false)
		{
			$this->msg[] = sprintf('ERROR: Could not update %s.%s, because the type is %s. It seems you using an older version of MetaModels.',
				$strTableName,
				$strColName,
				$objAttribute->getSQLDataType()
			);
			return;
		}

		// Second check if the fields already up to date.
		if ($this->isColumnFromType($strTableName, $strColName, array('blob', 'binary')))
		{
			$this->msg[] = sprintf('%s.%s seems to be already up to date.', $strTableName, $strColName);
			return;
		}

		// Get all Data.
		$objData = \Database::getInstance()
			->prepare(sprintf('SELECT id,%s FROM %s',
				$strColName,
				$strTableName
			))
			->execute();

		// Change field tpye.
		\MetaModels\Helper\TableManipulation::renameColumn(
			$strTableName,
			$strColName,
			$strColName,
			$objAttribute->getSQLDataType()
		);

		$this->msg[] = sprintf('Change %s.%s to %s.', $strTableName, $strColName, $objAttribute->getSQLDataType());

		$intCount = 0;
		while ($objData->next())
		{
			$arrData = deserialize($objData->$strColName, true);

			foreach ($arrData as $strKey => $strValue)
			{
				// Search file;
				$objFile = FilesModel::findByPk($strValue);
				if ($objFile == null)
				{
					continue;
				}

				// Replace in old array.
				$arrData[$strKey] = $objFile->uuid;
			}

			// Update the files.
			\Database::getInstance()
				->prepare(sprintf('UPDATE %s SET %s=? WHERE id=?',
					$strTableName,
					$strColName
				))
				->execute(serialize($arrData), $objData->id);

			$intCount++;
		}

		$this->msg[] = sprintf('Update %s entry|entries in %s.', $intCount, $strTableName);
	}
}

// Init.
$objRunner = new MetaModelsFileUpdater();

// Add ignored metamodels here.
//$objRunner->addIgnoredMetaModels('mm_z');

// Run Updater.
$objRunner->run();
?>

<?php foreach ($objRunner->getOutput() as $strMsg): ?>
	<p>
		<?php echo $strMsg; ?>
	</p>
<?php endforeach; ?>