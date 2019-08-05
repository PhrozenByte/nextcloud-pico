<?php
/**
 * CMS Pico - Create websites using Pico CMS for Nextcloud.
 *
 * @copyright Copyright (c) 2017, Maxence Lange (<maxence@artificial-owl.com>)
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

namespace OCA\CMSPico\Service;

use OCA\CMSPico\AppInfo\Application;
use OCA\CMSPico\Exceptions\ThemeNotCompatibleException;
use OCA\CMSPico\Exceptions\ThemeNotFoundException;
use OCA\CMSPico\Files\FolderInterface;
use OCA\CMSPico\Files\LocalFolder;
use OCA\CMSPico\Model\Theme;
use OCP\Files\AlreadyExistsException;
use OCP\Files\InvalidPathException;
use OCP\Files\NotFoundException;

class ThemesService
{
	/** @var ConfigService */
	private $configService;

	/** @var FileService */
	private $fileService;

	/** @var MiscService */
	private $miscService;

	/** @var bool */
	private $renewedETag = false;

	/**
	 * ThemesService constructor.
	 *
	 * @param ConfigService $configService
	 * @param FileService   $fileService
	 * @param MiscService   $miscService
	 */
	public function __construct(ConfigService $configService, FileService $fileService, MiscService $miscService)
	{
		$this->configService = $configService;
		$this->fileService = $fileService;
		$this->miscService = $miscService;
	}

	/**
	 * @param string $themeName
	 *
	 * @throws ThemeNotFoundException
	 * @throws ThemeNotCompatibleException
	 */
	public function assertValidTheme(string $themeName)
	{
		$themes = $this->getThemes();

		if (!isset($themes[$themeName])) {
			throw new ThemeNotFoundException();
		}

		if (!$themes[$themeName]['compat']) {
			throw new ThemeNotCompatibleException(
				$themeName,
				$themes[$themeName]['compatReason'],
				$themes[$themeName]['compatReasonData']
			);
		}
	}

	/**
	 * @return array[]
	 */
	public function getThemes(): array
	{
		return $this->getSystemThemes() + $this->getCustomThemes();
	}

	/**
	 * @return array[]
	 */
	public function getSystemThemes(): array
	{
		$json = $this->configService->getAppValue(ConfigService::SYSTEM_THEMES);
		return $json ? json_decode($json, true) : [];
	}

	/**
	 * @return array[]
	 */
	public function getCustomThemes(): array
	{
		$json = $this->configService->getAppValue(ConfigService::CUSTOM_THEMES);
		return $json ? json_decode($json, true) : [];
	}

	/**
	 * @return string[]
	 */
	public function getNewCustomThemes(): array
	{
		$customThemesFolder = $this->fileService->getAppDataFolder(PicoService::DIR_THEMES);
		$customThemesFolder->sync(FolderInterface::SYNC_SHALLOW);

		$currentThemes = $this->getThemes();

		$newCustomThemes = [];
		foreach ($customThemesFolder->listing() as $themeFolder) {
			$themeName = $themeFolder->getName();
			if ($themeFolder->isFolder() && !isset($currentThemes[$themeName])) {
				$newCustomThemes[] = $themeName;
			}
		}

		return $newCustomThemes;
	}

	/**
	 * @param string $themeName
	 *
	 * @return Theme
	 * @throws ThemeNotFoundException
	 */
	public function publishSystemTheme(string $themeName): Theme
	{
		if (!$themeName) {
			throw new ThemeNotFoundException();
		}

		$systemThemesFolder = $this->fileService->getSystemFolder(PicoService::DIR_THEMES);
		$systemThemesFolder->sync(FolderInterface::SYNC_SHALLOW);

		try {
			$systemThemeFolder = $systemThemesFolder->get($themeName);
			if (!$systemThemeFolder->isFolder()) {
				throw new ThemeNotFoundException();
			}
		} catch (NotFoundException $e) {
			throw new ThemeNotFoundException();
		}

		$themes = $this->getSystemThemes();
		$themes[$themeName] = $this->publishTheme($systemThemeFolder, Theme::THEME_TYPE_SYSTEM);
		$this->configService->setAppValue(ConfigService::SYSTEM_THEMES, json_encode($themes));

		return $themes[$themeName];
	}

	/**
	 * @param string $themeName
	 *
	 * @return Theme
	 * @throws ThemeNotFoundException
	 */
	public function publishCustomTheme(string $themeName): Theme
	{
		if (!$themeName) {
			throw new ThemeNotFoundException();
		}

		$appDataThemesFolder = $this->fileService->getAppDataFolder(PicoService::DIR_THEMES);
		$appDataThemesFolder->sync(FolderInterface::SYNC_SHALLOW);

		try {
			$appDataThemeFolder = $appDataThemesFolder->get($themeName);
			if (!$appDataThemeFolder->isFolder()) {
				throw new ThemeNotFoundException();
			}
		} catch (NotFoundException $e) {
			throw new ThemeNotFoundException();
		}

		$themes = $this->getCustomThemes();
		$themes[$themeName] = $this->publishTheme($appDataThemeFolder, Theme::THEME_TYPE_CUSTOM);
		$this->configService->setAppValue(ConfigService::CUSTOM_THEMES, json_encode($themes));

		return $themes[$themeName];
	}

	/**
	 * @param FolderInterface $themeSourceFolder
	 * @param int             $themeType
	 *
	 * @return Theme
	 */
	private function publishTheme(FolderInterface $themeSourceFolder, int $themeType): Theme
	{
		$publicThemesFolder = $this->getThemesFolder(true);

		$themeName = $themeSourceFolder->getName();
		$themeSourceFolder->sync();

		try {
			$themeFolder = $publicThemesFolder->get($themeName);
			if (!$themeFolder->isFolder()) {
				throw new InvalidPathException();
			}

			throw new AlreadyExistsException();
		} catch (NotFoundException $e) {}

		$themeFolder = $themeSourceFolder->copy($publicThemesFolder);
		return new Theme($themeFolder, $themeType);
	}

	/**
	 * @param string $themeName
	 */
	public function depublishCustomTheme(string $themeName)
	{
		if (!$themeName) {
			throw new ThemeNotFoundException();
		}

		$publicThemesFolder = $this->getThemesFolder();

		try {
			$themeFolder = $publicThemesFolder->get($themeName);
			if (!$themeFolder->isFolder()) {
				throw new ThemeNotFoundException();
			}

			$themeFolder->delete();
		} catch (NotFoundException $e) {
			throw new ThemeNotFoundException();
		}

		$customThemes = $this->getCustomThemes();
		unset($customThemes[$themeName]);
		$this->configService->setAppValue(ConfigService::CUSTOM_THEMES, json_encode($customThemes));
	}

	/**
	 * @param bool $renewETag
	 * @param bool $forceRenewETag
	 *
	 * @return LocalFolder
	 */
	private function getThemesFolder(bool $renewETag = false, bool $forceRenewETag = false): LocalFolder
	{
		$themesBaseFolder = $this->fileService->getPublicFolder(PicoService::DIR_THEMES);

		/** @var LocalFolder $themesFolder */
		$themesFolder = null;

		$themesETag = $this->configService->getAppValue(ConfigService::THEMES_ETAG);
		if ($themesETag) {
			$themesFolder = $themesBaseFolder->get($themesETag);
			if (!$themesFolder->isFolder()) {
				throw new InvalidPathException();
			}
		}

		if (($renewETag && !$this->renewedETag) || $forceRenewETag || !$themesFolder) {
			$themesETag = $this->miscService->getRandom();

			if ($themesFolder) {
				$themesFolder = $themesFolder->rename($themesETag);
			} else {
				$themesFolder = $themesBaseFolder->newFolder($themesETag);
			}

			$this->configService->setAppValue(ConfigService::THEMES_ETAG, $themesETag);
			$this->renewedETag = true;
		}

		return $themesFolder;
	}

	/**
	 * @return string
	 */
	public function getThemesPath(): string
	{
		$appPath = \OC_App::getAppPath(Application::APP_NAME) . '/';
		$themesPath = 'appdata_public/' . PicoService::DIR_THEMES . '/';
		$themesETag = $this->configService->getAppValue(ConfigService::THEMES_ETAG);
		return $appPath . $themesPath . ($themesETag ? $themesETag . '/' : '');
	}

	/**
	 * @return string
	 */
	public function getThemesUrl(): string
	{
		$appWebPath = \OC_App::getAppWebPath(Application::APP_NAME) . '/';
		$themesPath = 'appdata_public/' . PicoService::DIR_THEMES . '/';
		$themesETag = $this->configService->getAppValue(ConfigService::THEMES_ETAG);
		return $appWebPath . $themesPath . ($themesETag ? $themesETag . '/' : '');
	}
}
