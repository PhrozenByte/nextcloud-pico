<?php
/**
 * CMS Pico - Create websites using Pico CMS for Nextcloud.
 *
 * @copyright Copyright (c) 2019, Daniel Rudolf (<picocms.org@daniel-rudolf.de>)
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace OCA\CMSPico\Migration;

use OCA\CMSPico\Db\CoreRequestBuilder;
use OCA\CMSPico\Files\FolderInterface;
use OCA\CMSPico\Model\Template;
use OCA\CMSPico\Model\Theme;
use OCA\CMSPico\Model\WebsiteCore;
use OCA\CMSPico\Service\ConfigService;
use OCA\CMSPico\Service\FileService;
use OCA\CMSPico\Service\PicoService;
use OCA\CMSPico\Service\TemplatesService;
use OCA\CMSPico\Service\ThemesService;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010000From000908 extends SimpleMigrationStep
{
	use MigrationTrait;

	/** @var IDBConnection */
	private $databaseConnection;

	/** @var ConfigService */
	private $configService;

	/** @var TemplatesService */
	private $templatesService;

	/** @var ThemesService */
	private $themesService;

	/** @var FileService */
	private $fileService;

	/**
	 * Version010000 constructor.
	 */
	public function __construct()
	{
		$this->setLogger(\OC::$server->getLogger());

		$this->databaseConnection = \OC::$server->getDatabaseConnection();
		$this->configService = \OC::$server->query(ConfigService::class);
		$this->templatesService = \OC::$server->query(TemplatesService::class);
		$this->themesService = \OC::$server->query(ThemesService::class);
		$this->fileService = \OC::$server->query(FileService::class);
	}

	/**
	 * @param IOutput  $output
	 * @param \Closure $schemaClosure
	 * @param array    $options
	 */
	public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options)
	{
		$this->setOutput($output);

		// migrate the app config of custom templates and themes
		$this->migrateCustomTemplates();
		$this->migrateCustomThemes();

		// migrate old copies of system templates and themes in Nextcloud's data dir
		$this->migrateSystemTemplates();
		$themesMigrationMap = $this->migrateSystemThemes();

		// migrate cms_pico_websites database table
		$this->migratePrivateWebsites($themesMigrationMap);
	}

	/**
	 * @return void
	 */
	private function migrateCustomTemplates()
	{
		$customTemplatesJson = $this->configService->getAppValue(ConfigService::CUSTOM_TEMPLATES);
		$customTemplates = $customTemplatesJson ? json_decode($customTemplatesJson, true) : [];

		$newCustomTemplates = [];
		foreach ($customTemplates as $templateName) {
			$this->logInfo('Migrating Pico CMS custom template "%s"', $templateName);

			$newCustomTemplates[$templateName] = [
				'name' => $templateName,
				'type' => Template::TYPE_CUSTOM,
				'compat' => true
			];
		}

		$this->configService->setAppValue(ConfigService::CUSTOM_TEMPLATES, json_encode($newCustomTemplates));
	}

	/**
	 * @return void
	 */
	private function migrateCustomThemes()
	{
		$customThemesJson = $this->configService->getAppValue(ConfigService::CUSTOM_THEMES);
		$customThemes = $customThemesJson ? json_decode($customThemesJson, true) : [];

		$newCustomThemes = [];
		foreach ($customThemes as $themeName) {
			$this->logInfo('Migrating Pico CMS custom theme "%s"', $themeName);

			$newCustomThemes[$themeName] = [
				'name' => $themeName,
				'type' => Theme::TYPE_CUSTOM,
				'compat' => true
			];
		}

		$this->configService->setAppValue(ConfigService::CUSTOM_THEMES, json_encode($newCustomThemes));
	}

	/**
	 * @return array<string,string>
	 */
	private function migrateSystemTemplates()
	{
		$systemTemplatesFolder = $this->fileService->getSystemFolder(PicoService::DIR_TEMPLATES);
		$systemTemplatesFolder->sync(FolderInterface::SYNC_SHALLOW);

		$customTemplatesFolder = $this->fileService->getAppDataFolder(PicoService::DIR_TEMPLATES);
		$customTemplatesFolder->sync(FolderInterface::SYNC_SHALLOW);

		$templateExistsClosure = function ($templateName) use ($systemTemplatesFolder, $customTemplatesFolder) {
			return ($systemTemplatesFolder->exists($templateName) || $customTemplatesFolder->exists($templateName));
		};

		$templatesMigrationMap = [];
		foreach ($customTemplatesFolder as $templateFolder) {
			$templateName = $templateFolder->getName();
			if ($templateFolder->isFolder() && $systemTemplatesFolder->exists($templateName)) {
				$newTemplateName = $templateName . '-v0.9';
				for ($i = 1; $templateExistsClosure($newTemplateName); $i++) {
					$newTemplateName = $templateName . '-v0.9-dup' . $i;
				}

				$templateFolder->rename($newTemplateName);
				$this->templatesService->registerCustomTemplate($newTemplateName);
				$templatesMigrationMap[$templateName] = $newTemplateName;

				$this->logWarning(
					'Migrating Pico CMS system template "%s" to custom template "%s"',
					$templateName,
					$newTemplateName
				);
			}
		}

		return $templatesMigrationMap;
	}

	/**
	 * @return array<string,string>
	 */
	private function migrateSystemThemes()
	{
		$systemThemesFolder = $this->fileService->getSystemFolder(PicoService::DIR_THEMES);
		$systemThemesFolder->sync(FolderInterface::SYNC_SHALLOW);

		$customThemesFolder = $this->fileService->getAppDataFolder(PicoService::DIR_THEMES);
		$customThemesFolder->sync(FolderInterface::SYNC_SHALLOW);

		$themeExistsClosure = function ($themeName) use ($systemThemesFolder, $customThemesFolder) {
			return ($systemThemesFolder->exists($themeName) || $customThemesFolder->exists($themeName));
		};

		$themesMigrationMap = [];
		foreach ($customThemesFolder as $themeFolder) {
			$themeName = $themeFolder->getName();
			if ($themeFolder->isFolder() && $systemThemesFolder->exists($themeName)) {
				$newThemeName = $themeName . '-v0.9';
				for ($i = 1; $themeExistsClosure($newThemeName); $i++) {
					$newThemeName = $themeName . '-v0.9-dup' . $i;
				}

				$themeFolder->rename($newThemeName);
				$this->themesService->publishCustomTheme($newThemeName);
				$themesMigrationMap[$themeName] = $newThemeName;

				$this->logWarning(
					'Migrating Pico CMS system theme "%s" to custom theme "%s"',
					$themeName,
					$newThemeName
				);
			}
		}

		return $themesMigrationMap;
	}

	/**
	 * @param array $themesMigrationMap
	 */
	private function migratePrivateWebsites(array $themesMigrationMap)
	{
		$qbUpdate = $this->databaseConnection->getQueryBuilder();
		$qbUpdate
			->update(CoreRequestBuilder::TABLE_WEBSITES)
			->set('theme', $qbUpdate->createParameter('theme'))
			->set('type', $qbUpdate->createParameter('type'))
			->set('options', $qbUpdate->createParameter('options'))
			->where($qbUpdate->expr()->eq('id', $qbUpdate->createParameter('id')));

		$selectCursor = $this->databaseConnection->getQueryBuilder()
			->select('id', 'site', 'theme', 'type', 'options')
			->from(CoreRequestBuilder::TABLE_WEBSITES)
			->execute();

		while ($data = $selectCursor->fetch()) {
			$websiteTheme = $themesMigrationMap[$data['theme']] ?? $data['theme'];

			$websiteType = $data['type'] ?: WebsiteCore::TYPE_PUBLIC;
			$websiteOptions = $data['options'] ? json_decode($data['options'], true) : [];
			if (isset($websiteOptions['private'])) {
				$websiteType = $websiteOptions['private'] ? WebsiteCore::TYPE_PRIVATE : WebsiteCore::TYPE_PUBLIC;
				unset($websiteOptions['private']);
			}

			$qbUpdate->setParameters([
				'id' => $data['id'],
				'theme' => $websiteTheme,
				'type' => $websiteType,
				'options' => json_encode($websiteOptions),
			]);

			$this->logInfo(
				'Migrating Pico CMS website "%s" (private: %s, theme: "%s")',
				$data['site'],
				($websiteType === WebsiteCore::TYPE_PRIVATE) ? 'yes' : 'no',
				$websiteTheme
			);

			$qbUpdate->execute();
		}

		$selectCursor->closeCursor();
	}
}
