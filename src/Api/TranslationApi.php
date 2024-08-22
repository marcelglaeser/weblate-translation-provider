<?php
/*
 * This file is part of the weblate-translation-provider package.
 *
 * (c) 2022 m2m server software gmbh <tech@m2m.at>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace M2MTech\WeblateTranslationProvider\Api;

use M2MTech\WeblateTranslationProvider\Api\DTO\Component;
use M2MTech\WeblateTranslationProvider\Api\DTO\Translation;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Component\Translation\Exception\ProviderException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TranslationApi
{
    /** @var array<string,array<string,Translation>> */
    private static $translations = [];

    /** @var HttpClientInterface */
    private static $client;

    /** @var LoggerInterface */
    private static $logger;

    public static function setup(
        HttpClientInterface $client,
        LoggerInterface $logger
    ): void {
        self::$client = $client;
        self::$logger = $logger;

        self::$translations = [];
    }

    /**
     * @throws ExceptionInterface
     */
    public static function hasTranslation(Component $component, string $locale): bool
    {
        if (isset(self::$translations[$component->slug][$locale])) {
            return true;
        }

        if (isset(self::$translations[$component->slug])) {
            // already tried to load translation from server before

            return false;
        }

        /**
         * GET /api/components/(string: project)/(string: component)/translations/.
         *
         * @see https://docs.weblate.org/en/latest/api.html#get--api-components-(string-project)-(string-component)-translations-
         */
        $response = self::$client->request('GET', $component->translations_url);

        if (200 !== $response->getStatusCode()) {
            self::$logger->debug($response->getStatusCode() . ': ' . $response->getContent(false));
            throw new ProviderException('Unable to get weblate components translations for ' . $component->slug . '.', $response);
        }

        $results = $response->toArray()['results'];
        foreach ($results as $result) {
            $translation = new Translation($result);
            self::$translations[$component->slug][$translation->language_code] = $translation;
            self::$logger->debug('Loaded translation ' . $component->slug . ' ' . $translation->language_code);
        }

        if (isset(self::$translations[$component->slug][$locale])) {
            return true;
        }

        return false;
    }

    /**
     * @throws ExceptionInterface
     */
    public static function getTranslation(Component $component, string $locale): Translation
    {
        if (self::hasTranslation($component, $locale)) {
            return self::$translations[$component->slug][$locale];
        }

        return self::addTranslation($component, $locale);
    }

    /**
     * @throws ExceptionInterface
     */
    public static function addTranslation(Component $component, string $locale): Translation
    {
        /**
         * POST /api/components/(string: project)/(string: component)/translations/.
         *
         * @see https://docs.weblate.org/en/latest/api.html#post--api-components-(string-project)-(string-component)-translations-
         */
        $response = self::$client->request('POST', $component->translations_url, [
            'body' => ['language_code' => $locale],
        ]);

        if (201 !== $response->getStatusCode()) {
            self::$logger->debug($response->getStatusCode() . ': ' . $response->getContent(false));
            throw new ProviderException('Unable to add weblate components translation for ' . $component->slug . ' ' . $locale . '.', $response);
        }

        $result = $response->toArray()['data'];
        $translation = new Translation($result);
        $translation->created = true;
        self::$translations[$component->slug][$locale] = $translation;

        self::$logger->debug('Added translation ' . $component->slug . ' ' . $locale);

        return $translation;
    }

    /**
     * @throws ExceptionInterface
     */
    public static function uploadTranslation(Translation $translation, string $content): void
    {
        $content = str_replace('<trans-unit', '<trans-unit xml:space="preserve"', $content);

        /**
         * POST /api/translations/(string: project)/(string: component)/(string: language)/file/.
         *
         * @see https://docs.weblate.org/en/latest/api.html#post--api-translations-(string-project)-(string-component)-(string-language)-file-
         */
        $formFields = [
            'method' => 'replace',
            'file' => new DataPart($content, $translation->filename),
        ];
        $formData = new FormDataPart($formFields);

        $response = self::$client->request('POST', $translation->file_url, [
            'headers' => $formData->getPreparedHeaders()->toArray(),
            'body' => $formData->bodyToString(),
        ]);

        if (200 !== $response->getStatusCode()) {
            self::$logger->debug($response->getStatusCode() . ': ' . $response->getContent(false));
            throw new ProviderException('Unable to upload weblate translation ' . $content . '.', $response);
        }

        self::$logger->debug('Uploaded translation ' . $translation->filename);
    }

    /**
     * @throws ExceptionInterface
     */
    public static function downloadTranslation(Translation $translation, string $format = 'json'): string
    {
        $url = $translation->file_url;
        if ($format !== 'json') {
            $url .= '?format=' . $format;
        }

        try {
            // Führe die API-Anfrage aus
            $response = self::$client->request('GET', $url);
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false); // Inhalt ohne zu parsen

            // Protokolliere den Statuscode und den vollständigen Antwortinhalt
            self::$logger->debug('Requesting URL: ' . $url);
            self::$logger->debug('HTTP Status Code: ' . $statusCode);
            self::$logger->debug('Response Content: ' . $content);

            if ($statusCode !== 200) {
                throw new ProviderException('Unable to download weblate translation. HTTP Status: ' . $statusCode . ', Content: ' . $content);
            }

            // Wenn das Format XLIFF ist, gib den Inhalt direkt zurück
            if ($format === 'xliff' || stripos($content, '<?xml') !== false) {
                return $content;  // XLIFF-Inhalt direkt zurückgeben
            }

            // Falls JSON erwartet wird, versuche die Antwort zu parsen
            if ($format === 'json') {
                try {
                    $jsonData = $response->toArray();  // JSON als Array
                } catch (\Exception $jsonException) {
                    self::$logger->error('Failed to parse JSON response: ' . $jsonException->getMessage());
                    self::$logger->error('Raw Content: ' . $content);
                    throw $jsonException;  // Werfe den Fehler erneut
                }

                // Hier die Umwandlung von JSON zu XLIFF starten
                $xliffContent = self::convertJsonToXliff($jsonData, $translation);
                return $xliffContent;
            } else {
                return $content;  // Andere Formate als roher Inhalt
            }
        } catch (\Exception $e) {
            self::$logger->error('Error while requesting URL: ' . $url);
            self::$logger->error('Exception Message: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Konvertiert JSON-Daten in das XLIFF-Format
     */
    private static function convertJsonToXliff(array $jsonData, Translation $translation): string
    {
        // Korrekte XML-Deklaration und XLIFF-Struktur mit dem 'original'-Attribut
        $xliff = '<?xml version="1.0" encoding="UTF-8"?>';
        $xliff .= '<xliff version="1.2" xmlns="urn:oasis:names:tc:xliff:document:1.2">';

        // Füge das 'original'-Attribut hinzu, hier dynamisch basierend auf dem Dateinamen
        $original = htmlspecialchars($translation->filename ?? 'unknown_file');

        $xliff .= '<file source-language="' . htmlspecialchars($translation->source_language) . '" target-language="' . htmlspecialchars($translation->language_code) . '" datatype="plaintext" original="' . $original . '">';
        $xliff .= '<body>';

        foreach ($jsonData as $key => $value) {
            // Überprüfe, ob $key und $value Strings sind, bevor htmlspecialchars() aufgerufen wird
            if (is_string($key) && is_string($value)) {
                $xliff .= '<trans-unit id="' . htmlspecialchars($key) . '">';
                $xliff .= '<source>' . htmlspecialchars($key) . '</source>';
                $xliff .= '<target>' . htmlspecialchars($value) . '</target>';
                $xliff .= '</trans-unit>';
            } elseif (is_array($value)) {
                // Falls $value ein Array ist, durchlaufe es und füge jedes Element hinzu
                foreach ($value as $subKey => $subValue) {
                    if (is_string($subKey) && is_string($subValue)) {
                        $xliff .= '<trans-unit id="' . htmlspecialchars($subKey) . '">';
                        $xliff .= '<source>' . htmlspecialchars($subKey) . '</source>';
                        $xliff .= '<target>' . htmlspecialchars($subValue) . '</target>';
                        $xliff .= '</trans-unit>';
                    }
                }
            }
        }

        $xliff .= '</body>';
        $xliff .= '</file>';
        $xliff .= '</xliff>';

        return $xliff;
    }
}
