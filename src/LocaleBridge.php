<?php

/*
 *
 * Licensed under the Apache License, Version 2.0.
 *
 * You may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * @author Zwuiix
 * @link https://github.com/Zwuiix-cmd/locale-bridge
 *
 */

declare(strict_types=1);

namespace zwuiix\locale;

use pocketmine\event\EventPriority;
use pocketmine\event\player\PlayerCreationEvent;
use pocketmine\lang\KnownTranslationKeys as Keys;
use pocketmine\lang\Language;
use pocketmine\plugin\Plugin;
use pocketmine\Server;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Filesystem\Path;
use zwuiix\locale\event\LanguageCreationEvent;
use function array_key_first;
use function array_merge;
use function class_exists;
use function count;
use function ltrim;
use function md5;
use function mkdir;
use function sprintf;

final class LocaleBridge
{
	private const PLAYER_CLASS_PREFIX = 'LocaleBridgePlayer_';

	/** @var Language[] */
	private static array $langs = [];

	private static bool $isRegistered = false;

	/**
	 * @return void
	 */
	private function __construct()
	{
		// NOOP
	}

	public static function isRegistered() : bool
	{
		return self::$isRegistered;
	}

	public static function register(Plugin $plugin) : void
	{
		if (self::isRegistered()) {
			throw new RuntimeException("LocaleBridge is already registered");
		}

		Server::getInstance()->getPluginManager()->registerEvent(PlayerCreationEvent::class, function(PlayerCreationEvent $ev) : void {
			$playerClass = ltrim($ev->getPlayerClass(), '\\');
			$className = self::PLAYER_CLASS_PREFIX . md5(self::class . "\0" . $playerClass);
			if (!class_exists($className, false)) {
				eval(sprintf(
					<<<'PHP'
class %s extends \%s
{
	public function getLanguage() : \%s
	{
		return \%s::fromLocale($this->getLocale());
	}
}
PHP,
					$className,
					$playerClass,
					Language::class,
					self::class,
				));
			}

			$ev->setPlayerClass($className);
		}, EventPriority::HIGHEST, $plugin);
		self::$isRegistered = true;
	}

	public static function load(Plugin $plugin, string $path) : void
	{
		$path = Path::join($path);
		@mkdir($path);

		$server = Server::getInstance();
		$logger = $plugin->getLogger();

		$langs = Language::getLanguageList($path);
		if (empty($langs)) {
			$logger->warning('No languages found in ' . $path);

			return;
		}

		/** @var array<string, string> $langs */
		foreach ($langs as $code => $name) {
			self::$langs[$code] = new Language($code, $path, $code);
		}

		$serverLang = $server->getLanguage()->getLang();
		$fallbackLang = isset($langs[$serverLang]) ? $serverLang : array_key_first($langs);

		$ref = new ReflectionProperty(Language::class, 'lang');
		foreach ($langs = Language::getLanguageList() as $code => $name) {
			$languageCode = (string) $code;
			$event = new LanguageCreationEvent($languageCode);
			$event->call();

			$langClass = $event->getLanguageClass();
			$lang = new $langClass($languageCode);

			$definitions = (self::$langs[$code] ?? self::$langs[$fallbackLang])->getAll();
			$definitions = array_merge(self::$langs[$fallbackLang]->getAll(), $definitions);
			self::$langs[$code] = $lang;

			unset($definitions[Keys::LANGUAGE_NAME]);

			$ref->setValue(self::$langs[$code], array_merge($lang->getAll(), $definitions));
			$logger->notice("Language {$code} (" . $lang->getName() . ') loaded');
		}
		$logger->info('Loaded ' . count($langs) . ' languages');

		$reflection = new ReflectionProperty(Server::class, 'language');
		$reflection->setValue($server, self::$langs[$serverLang]);

		if ($serverLang !== $fallbackLang) {
			$logger->notice('Language ' . $serverLang . ' not found, falling back to ' . $fallbackLang . ' (' . self::$langs[$fallbackLang]->getName() . ')');
		}
	}

	/**
	 * @param string|null $lang
	 *
	 * @return Language
	 */
	public static function fromLocale(?string $lang = null) : Language
	{
		$defaultLang = Server::getInstance()->getLanguage()->getLang();
		$lang ??= $defaultLang;

		if (isset(self::$langs[$lang])) {
			return self::$langs[$lang];
		}

		$mappedCode = match ($lang) {
			'zh_CN', 'zh_TW' => 'chs',
			'de_DE' => 'deu',
			'en_GB', 'en_US' => 'eng',
			'fr_CA', 'fr_FR' => 'fra',
			'it_IT' => 'ita',
			'ja_JP' => 'jpn',
			'ko_KR' => 'kor',
			'nl_NL' => 'nld',
			'ru_RU' => 'rus',
			'es_ES', 'es_MX' => 'spa',
			'tr_TR' => 'tur',
			default => $defaultLang,
		};

		return self::$langs[$mappedCode] ?? self::$langs[$defaultLang];
	}
}
