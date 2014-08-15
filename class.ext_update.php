<?php
/***************************************************************
 *  Copyright notice
 *
 *  (c) 2014 Xavier Perseguers <xavier@causal.ch>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/


$BACK_PATH = $GLOBALS['BACK_PATH'] . TYPO3_mainDir;

/**
 * Class to be used to migrate global configuration from v1.1.x and below to
 * configuration records in v1.2.
 *
 * @category    Extension Manager
 * @package     TYPO3
 * @subpackage  tx_igldapssoauth
 * @author      Xavier Perseguers <xavier@causal.ch>
 * @license     http://www.gnu.org/copyleft/gpl.html
 */
class ext_update extends t3lib_SCbase {

	/** @var string */
	protected $extKey = 'ig_ldap_sso_auth';

	/** @var array */
	protected $configuration;

	/** @var array */
	protected $operations = array();

	/** @var string */
	protected $table = 'tx_igldapssoauth_config';

	/**
	 * Default constructor.
	 */
	public function __construct() {
		$this->configuration = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf'][$this->extKey]);
	}

	/**
	 * Checks whether the "UPDATE!" menu item should be
	 * shown.
	 *
	 * @return boolean
	 */
	public function access() {
		if ($this->checkV1xToV12()) {
			$this->operations[] = 'upgradeV1xToV12';
		}
		if ($this->checkV12ToV13()) {
			$this->operations[] = 'upgradeV12ToV13';
		}
		if ($this->checkEuLdap()) {
			$this->operations[] = 'migrateEuLdap';
		}

		return count($this->operations) > 0;
	}

	/**
	 * Returns TRUE if upgrade wizard from v1.x to v1.2 should be run.
	 *
	 * @return bool
	 */
	protected function checkV1xToV12() {
		$updateNeeded = FALSE;
		$mapping = $this->getMapping();

		$where = array();
		foreach ($mapping as $configKey => $field) {
			if (!empty($this->configuration[$configKey])) {
				// Global setting present => should be migrated if not already done
				$updateNeeded = TRUE;
			}
			$where[] = $field . '=' . $this->getDatabaseConnection()->fullQuoteStr('', $this->table);
		}
		if ($updateNeeded) {
			$oldConfigurationRecords = $this->getDatabaseConnection()->exec_SELECTcountRows(
				'*',
				$this->table,
				implode(' AND ', $where)
			);
			$updateNeeded = ($oldConfigurationRecords > 0);
		}

		return $updateNeeded;
	}

	/**
	 * Returns TRUE if upgrade wizard from v1.2 to v1.3 should be run.
	 *
	 * @return bool
	 */
	protected function checkV12ToV13() {
		$oldConfigurationRecords = $this->getDatabaseConnection()->exec_SELECTcountRows(
			'*',
			$this->table,
			'group_membership=0'
		);
		return $oldConfigurationRecords > 0;
	}

	/**
	 * Returns TRUE if upgrade wizard for legacy EXT:eu_ldap records should be run.
	 *
	 * @return bool
	 */
	protected function checkEuLdap() {
		$table = 'tx_euldap_server';
		$migrationField = 'tx_igldapssoauth_migrated';

		// We check the database table itself and not whether EXT:eu_ldap is loaded
		// because it may have been deactivated since it is not incompatible
		$existingTables = $this->getDatabaseConnection()->admin_get_tables();
		if (!isset($existingTables[$table])) {
			return FALSE;
		}

		// Ensure the column used to flag processed records is present
		$fields = $this->getDatabaseConnection()->admin_get_fields($table);
		if (!isset($fields[$migrationField])) {
			$alterTableQuery = 'ALTER TABLE ' . $table . ' ADD ' . $migrationField . ' tinyint(4) NOT NULL default \'0\'';
			// Method admin_query() will parse the query and make it compatible with DBAL, if needed
			$this->getDatabaseConnection()->admin_query($alterTableQuery);
		}

		$euLdapConfigurationRecords = $this->getDatabaseConnection()->exec_SELECTcountRows(
			'*',
			$table,
			$migrationField . '=0'
		);
		return $euLdapConfigurationRecords > 0;
	}

	/**
	 * Main method that is called whenever UPDATE! menu
	 * was clicked.
	 *
	 * @return string HTML to display
	 */
	public function main() {
		$out = array();

		foreach ($this->operations as $operation) {
			$out[] = call_user_func(array($this, $operation));
		}

		return implode(LF, $out);
	}

	/**
	 * Upgrades configuration from v1.x to v1.2.
	 *
	 * @return string
	 */
	protected function upgradeV1xToV12() {
		$mapping = $this->getMapping();

		$fieldValues = array(
			'tstamp' => $GLOBALS['EXEC_TIME'],
		);
		$where = array();
		foreach ($mapping as $configKey => $field) {
			if (!empty($this->configuration[$configKey])) {
				// Global setting present => should be migrated
				$fieldValues[$field] = $this->configuration[$configKey];
			}
			$where[] = $field . '=' . $this->getDatabaseConnection()->fullQuoteStr('', $this->table);
		}
		$oldConfigurationRecords = $this->getDatabaseConnection()->exec_SELECTgetRows(
			'uid',
			$this->table,
			implode(' AND ', $where)
		);

		$i = 0;
		foreach ($oldConfigurationRecords as $oldConfigurationRecord) {
			$this->getDatabaseConnection()->exec_UPDATEquery(
				$this->table,
				'uid=' . $oldConfigurationRecord['uid'],
				$fieldValues
			);
			$i++;
		}

		return $this->formatOk('Successfully updated ' . $i . ' configuration record' . ($i > 1 ? 's' : ''));
	}

	/**
	 * Upgrades configuration from v1.2 to v1.3.
	 *
	 * @return string
	 */
	protected function upgradeV12ToV13() {
		$this->getDatabaseConnection()->exec_UPDATEquery(
			$this->table,
			'1=1',
			array(
				'group_membership' => (bool) $this->configuration['evaluateGroupsFromMembership'] ? 2 : 1,
			)
		);

		return $this->formatOk('Successfully transferred how the group membership should be extracted from LDAP from global configuration to the configuration records.');
	}

	/**
	 * Migrates configuration records from EXT:eu_ldap.
	 *
	 * @return string
	 */
	protected function migrateEuLdap() {
		$out = array();

		// STEP 1: check global options
		$this->migrateEuLdapGlobalOptions($out);

		// STEP 2: migrate configuration records
		$this->migrateEuLdapConfiguration($out);

		// STEP 3: migrate users
		$this->migrateEuLdapUsers($out);

		return implode(LF, $out);
	}

	/**
	 * Migrates global options from eu_ldap.
	 *
	 * @param array &$out
	 * @return void
	 */
	protected function migrateEuLdapGlobalOptions(array &$out) {
		$automaticImportRows = $this->getDatabaseConnection()->exec_SELECTgetRows(
			'DISTINCT authenticate_be, automatic_import, doitfe',
			'tx_euldap_server',
			'1=1'
		);

		$hasBackendAuthentication = FALSE;
		$hasFrontendAuthentication = FALSE;
		$shouldAutomaticallyImportBackendUsers = FALSE;
		$shouldAutomaticallyImportBackendGroups = FALSE;
		$shouldAutomaticallyImportFrontendUsers = FALSE;
		$shouldAutomaticallyImportFrontendGroups = FALSE;

		foreach ($automaticImportRows as $row) {
			if ($row['authenticate_be'] == 1 || $row['authenticate_be'] == 2) {
				$hasBackendAuthentication = TRUE;
				if ($row['automatic_import'] == 1) {
					$shouldAutomaticallyImportBackendUsers = TRUE;
				}
				if ($row['doitfe'] == 1) {
					$shouldAutomaticallyImportBackendGroups = TRUE;
				}
			}
			if ($row['authenticate_be'] == 0 || $row['authenticate_be'] == 2) {
				$hasFrontendAuthentication = TRUE;
				if ($row['automatic_import'] == 1) {
					$shouldAutomaticallyImportFrontendUsers = TRUE;
				}
				if ($row['doitfe'] == 1) {
					$shouldAutomaticallyImportFrontendGroups = TRUE;
				}
			}
		}

		if ($hasBackendAuthentication && $this->configuration['enableBELDAPAuthentication'] == 0) {
			$out[] = $this->formatWarning('eu_ldap was configured for backend authentication but this extension does not. You should set enableBELDAPAuthentication = 1.');
		} elseif (!$hasBackendAuthentication && $this->configuration['enableBELDAPAuthentication'] == 1) {
			$out[] = $this->formatWarning('eu_ldap was NOT configured for backend authentication but this extension does. You should probably set enableBELDAPAuthentication = 0.');
		}
		if ($hasFrontendAuthentication && $this->configuration['enableFELDAPAuthentication'] == 0) {
			$out[] = $this->formatWarning('eu_ldap was configured for frontend authentication but this extension does not. You should set enableFELDAPAuthentication = 1.');
		} elseif (!$hasFrontendAuthentication && $this->configuration['enableFELDAPAuthentication'] == 1) {
			$out[] = $this->formatWarning('eu_ldap was NOT configured for frontend authentication but this extension does. You should probably set enableFELDAPAuthentication = 0.');
		}

		if ($shouldAutomaticallyImportBackendUsers && $this->configuration['TYPO3BEUserExist'] == '1') {
			$out[] = $this->formatWarning('eu_ldap was configured to automatically import backend users but this extension does not. You should set TYPO3BEUserExist = 0.');
		} elseif (!$shouldAutomaticallyImportBackendUsers && $this->configuration['TYPO3BEUserExist'] == '0') {
			$out[] = $this->formatWarning('eu_ldap was configured to NEVER automatically import backend users but this extension does. You should set TYPO3BEUserExist = 1.');
		}
		if ($shouldAutomaticallyImportFrontendUsers && $this->configuration['TYPO3FEUserExist'] == '1') {
			$out[] = $this->formatWarning('eu_ldap was configured to automatically import frontend users but this extension does not. You should set TYPO3FEUserExist = 0.');
		} elseif (!$shouldAutomaticallyImportFrontendUsers && $this->configuration['TYPO3FEUserExist'] == '0') {
			$out[] = $this->formatWarning('eu_ldap was configured to NEVER automatically import frontend users but this extension does. You should set TYPO3FEUserExist = 1.');
		}

		if ($shouldAutomaticallyImportBackendGroups && $this->configuration['TYPO3BEGroupsNotSynchronize'] == '1') {
			$out[] = $this->formatWarning('eu_ldap was configured to automatically import backend groups but this extension does not. You should set TYPO3BEGroupsNotSynchronize = 0.');
		} elseif (!$shouldAutomaticallyImportBackendGroups && $this->configure['TYPO3BEGroupsNotSynchronize'] == '0') {
			$out[] = $this->formatWarning('eu_ldap was configured to NEVER automatically import backend group but this extension does. You should set TYPO3BEGroupsNotSynchronize = 1.');
		}
		if ($shouldAutomaticallyImportFrontendGroups && $this->configuration['TYPO3FEGroupsNotSynchronize'] == '1') {
			$out[] = $this->formatWarning('eu_ldap was configured to automatically import frontend groups but this extension does not. You should set TYPO3FEGroupsNotSynchronize = 0.');
		} elseif (!$shouldAutomaticallyImportFrontendGroups && $this->configure['TYPO3FEGroupsNotSynchronize'] == '0') {
			$out[] = $this->formatWarning('eu_ldap was configured to NEVER automatically import frontend group but this extension does. You should set TYPO3FEGroupsNotSynchronize = 1.');
		}
	}

	/**
	 * Migrates eu_ldap configuration records.
	 *
	 * @param array &$out
	 * @return void
	 */
	protected function migrateEuLdapConfiguration(array &$out) {
		$euLdapConfigurationRecords = $this->getDatabaseConnection()->exec_SELECTgetRows(
			'*',
			'tx_euldap_server',
			'tx_igldapssoauth_migrated=0'
		);
		foreach ($euLdapConfigurationRecords as $legacy) {
			$hasBackendAuthentication = $legacy['authenticate_be'] == 1 || $legacy['authenticate_be'] == 2;
			$hasFrontendAuthentication = $legacy['authenticate_be'] == 0 || $legacy['authenticate_be'] == 2;

			$data = array(
				'pid'                => 0,
				'tstamp'             => $GLOBALS['EXEC_TIME'],
				'crdate'             => $GLOBALS['EXEC_TIME'],
				'cruser_id'          => $GLOBALS['BE_USER']->user['uid'],
				'name'               => '[eu_ldap] ' . $legacy['server'],
				'ldap_server'        => $legacy['servertype'] == 3 ? 0 : 1,
				'ldap_charset'       => $legacy['characterset'],
				'ldap_protocol'      => $legacy['version'],
				'ldap_host'          => preg_replace('#ldaps?://#', '', $legacy['server']),
				'ldap_port'          => $legacy['port'],
				'ldap_tls'           => 0,
				'ldap_binddn'        => $legacy['servertype'] == 2 || $legacy['servertype'] == 3
					? $legacy['user']
					: (
						$legacy['servertype'] == 0
							? $legacy['domain'] . '\\' . $legacy['user']
							: $legacy['user'] . '@' . $legacy['domain']
					),
				'ldap_password'      => $legacy['password'],
				'be_users_basedn'    => $hasBackendAuthentication ? $legacy['base_dn'] : '',
				'be_users_filter'    => $hasBackendAuthentication ? str_replace('<search>', '{USERNAME}', $legacy['filter']) : '',
				'be_users_mapping'   => '', // computed below
				'be_groups_basedn'   => $hasBackendAuthentication ? $legacy['base_dn'] : '',
				'be_groups_filter'   => '',	// computed below
				'be_groups_mapping'  => $hasBackendAuthentication
											? implode(LF, array(
												'title = <cn>',
												'tstamp = {DATE}',
											)) : '',
				'fe_users_basedn'    => $hasFrontendAuthentication ? $legacy['base_dn'] : '',
				'fe_users_filter'    => $hasFrontendAuthentication ? str_replace('<search>', '{USERNAME}', $legacy['filter']) : '',
				'fe_users_mapping'   => '', // computed below
				'fe_groups_basedn'   => $hasFrontendAuthentication ? $legacy['base_dn'] : '',
				'fe_groups_filter'   => '', // computed below
				'fe_groups_mapping'  => $hasFrontendAuthentication
											? implode(LF, array(
												'pid = ' . (int)$legacy['feuser_pid'],
												'title = <cn>',
												'tstamp = {DATE}',
											)) : '',
				'be_groups_required' => $hasBackendAuthentication ? $legacy['matchgrps'] : '',
				'be_groups_assigned' => $legacy['be_group'],
				'fe_groups_required' => $hasFrontendAuthentication ? $legacy['matchgrps'] : '',
				'fe_groups_assigned' => $legacy['fe_group'],
				'group_membership'   => $legacy['memberof'] == 1
					? (
						$legacy['servertype'] == 3
							? tx_igldapssoauth_config::GROUP_MEMBERSHIP_FROM_GROUP
							: tx_igldapssoauth_config::GROUP_MEMBERSHIP_FROM_MEMBER
					)
					: 0,	// No standard mapping, will have to be manually configured
				'sorting'            => $legacy['sorting'],
			);

			if ($hasBackendAuthentication) {
				$mapping = array();
				$mapping[] = 'tstamp = ' . (!empty($legacy['timestamp']) ? '<' . $legacy['timestamp'] . '>' : '{DATE}');

				switch ($legacy['servertype']) {
					case 0:
					case 1:
						$mapping[] = 'usergroup = <memberof>';
						$data['be_groups_filter'] = '(objectClass=posixGroup)';
						break;
					case 2:
						$mapping[] = 'usergroup = <groupmembership>';
						$data['be_groups_filter'] = '(objectClass=posixGroup)';
						break;
					case 3:
						$data['be_groups_filter'] = '(&(memberUid={USERUID})(objectClass=posixGroup))';
						break;
				}

				$mapping[] = 'realName = <' . $legacy['name'] . '>';
				if (!empty($legacy['mail'])) {
					$mapping[] = 'email = <' . $legacy['mail'] . '>';
				}

				$data['be_users_mapping'] = implode(LF, $mapping);
			}
			if ($hasFrontendAuthentication) {
				$mapping = array();
				$mapping[] = 'pid = ' . (int)$legacy['feuser_pid'];
				$mapping[] = 'tstamp = ' . (!empty($legacy['timestamp']) ? '<' . $legacy['timestamp'] . '>' : '{DATE}');

				switch ($legacy['servertype']) {
					case 0:
					case 1:
						$mapping[] = 'usergroup = <memberof>';
						$data['fe_groups_filter'] = '(objectClass=posixGroup)';
						break;
					case 2:
						$mapping[] = 'usergroup = <groupmembership>';
						$data['fe_groups_filter'] = '(objectClass=posixGroup)';
						break;
					case 3:
						$data['fe_groups_filter'] = '(&(memberUid={USERUID})(objectClass=posixGroup))';
						break;
				}

				if (!empty($legacy['mail'])) {
					$mapping[] = 'email = <' . $legacy['mail'] . '>';
				}
				$mapping[] = 'name = <' . $legacy['name'] . '>';
				if (!empty($legacy['address'])) {
					$mapping[] = 'address = <' . $legacy['address'] . '>';
				}
				if (!empty($legacy['zip'])) {
					$mapping[] = 'zip = <' . $legacy['zip'] . '>';
				}
				if (!empty($legacy['city'])) {
					$mapping[] = 'city = <' . $legacy['city'] . '>';
				}
				if (!empty($legacy['country'])) {
					$mapping[] = 'country = <' . $legacy['country'] . '>';
				}
				if (!empty($legacy['phone'])) {
					$mapping[] = 'telephone = <' . $legacy['phone'] . '>';
				}
				if (!empty($legacy['fax'])) {
					$mapping[] = 'fax = <' . $legacy['fax'] . '>';
				}
				if (!empty($legacy['www'])) {
					$mapping[] = 'www = <' . $legacy['www'] . '>';
				}

				$additionalInstructions = t3lib_div::trimExplode(',', $legacy['map_additional_fields'], TRUE);
				foreach ($additionalInstructions as $additionalInstruction) {
					list($dbField, $ldapField) = explode('=', $additionalInstruction, 2);
					$mapping[] = $dbField . ' = <' . $ldapField . '>';
				}

				$data['fe_users_mapping'] = implode(LF, $mapping);
			}

			if ($data['be_groups_required'] === '*') {
				// Replace '*' by every local BE group
				$groups = $this->getDatabaseConnection()->exec_SELECTgetRows(
					'uid',
					'be_groups',
					'hidden=0 AND deleted=0 AND tx_igldapssoauth_dn=\'\' AND eu_ldap=0',
					'',
					'',
					'',
					'uid'
				);
				$data['be_groups_required'] = implode(',', array_keys($groups));
			}
			if ($data['fe_groups_required'] === '*') {
				// Replace '*' by every local FE group
				$groups = $this->getDatabaseConnection()->exec_SELECTgetRows(
					'uid',
					'fe_groups',
					'hidden=0 AND deleted=0 AND tx_igldapssoauth_dn=\'\' AND eu_ldap=0',
					'',
					'',
					'',
					'uid'
				);
				$data['fe_groups_required'] = implode(',', array_keys($groups));
			}
			if ($legacy['only_emailusers'] == 1) {
				$emailAttribute = !empty($legacy['mail']) ? $legacy['mail'] : 'mail';
				if ($hasBackendAuthentication) {
					$data['be_users_filter'] = sprintf('(&%s(%s=*))', $data['be_users_filter'], $emailAttribute);
				}
				if ($hasFrontendAuthentication) {
					$data['fe_users_filter'] = sprintf('(&%s(%s=*))', $data['fe_users_filter'], $emailAttribute);
				}
			}

			// Insert the migrated record to ig_ldap_sso_auth
			$this->getDatabaseConnection()->exec_INSERTquery($this->table, $data);
			if ($this->getDatabaseConnection()->sql_affected_rows() == 1) {
				$this->getDatabaseConnection()->exec_UPDATEquery(
					'tx_euldap_server',
					'uid=' . $legacy['uid'],
					array(
						'tx_igldapssoauth_migrated' => 1,
					)
				);
			}
		}

		$out[] = $this->formatOk('Successfully migrated eu_ldap configuration records.');
	}

	/**
	 * Migrates backend and/or frontend users that were previously imported
	 * with eu_ldap.
	 *
	 * @param array &$out
	 * @return void
	 */
	protected function migrateEuLdapUsers(array &$out) {
		foreach (array('fe_users', 'be_users') as $table) {
			$query = <<<SQL
UPDATE $table
SET tx_igldapssoauth_dn=tx_euldap_dn
WHERE tx_igldapssoauth_dn='' AND tx_euldap_dn<>''
SQL;
			$this->getDatabaseConnection()->sql_query($query);
		}

		$out[] = $this->formatOk('Successfully migrated eu_ldap users.');
	}

	/**
	 * Returns the mapping between global configuration options and
	 * configuration record fields.
	 *
	 * @return array
	 */
	protected function getMapping() {
		return array(
			'requiredLDAPBEGroups' => 'be_groups_required',
			'assignBEGroups' => 'be_groups_assigned',
			'updateAdminAttribForGroups' => 'be_groups_admin',
			'requiredLDAPFEGroups' => 'fe_groups_required',
			'assignFEGroups' => 'fe_groups_assigned',
		);
	}

	/**
	 * Creates an OK message for backend output.
	 *
	 * @param string $message
	 * @param bool $hsc
	 * @return string
	 */
	protected function formatOk($message, $hsc = TRUE) {
		$output = '<div class="typo3-message message-ok">';
		//$output .= '<div class="message-header">Message head</div>';
		if ($hsc) {
			$message = nl2br(htmlspecialchars($message));
		}
		$output .= '<div class="message-body">' . $message . '</div>';
		$output .= '</div>';

		return $output;
	}

	/**
	 * Creates a WARNING message for backend output.
	 *
	 * @param string $message
	 * @param bool $hsc
	 * @return string
	 */
	protected function formatWarning($message, $hsc = TRUE) {
		$output = '<div class="typo3-message message-warning">';
		//$output .= '<div class="message-header">Message head</div>';
		if ($hsc) {
			$message = nl2br(htmlspecialchars($message));
		}
		$output .= '<div class="message-body">' . $message . '</div>';
		$output .= '</div>';

		return $output;
	}

	/**
	 * Returns the database connection.
	 *
	 * @return t3lib_DB
	 */
	protected function getDatabaseConnection() {
		return $GLOBALS['TYPO3_DB'];
	}

}
