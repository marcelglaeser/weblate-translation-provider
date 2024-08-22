<?php
/*
 * This file is part of the weblate-translation-provider package.
 *
 * (c) 2022 m2m server software gmbh <tech@m2m.at>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace M2MTech\WeblateTranslationProvider\Api\DTO;

class Translation extends DTO
{
    /** @var string */
    public $language_code;

    /** @var string */
    public $filename;

    /** @var string */
    public $file_url;

    /** @var string */
    public $units_list_url;

    /** @var bool */
    public $created = false;

    public function __construct(array $data)
    {
        // Setze die source_language, falls sie in den Daten vorhanden ist
        $this->source_language = $data['source_language'] ?? 'en'; // Standardwert 'en', wenn nicht vorhanden
        $this->language_code = $data['language_code'] ?? 'en';
        $this->filename = $data['filename'] ?? 'translation.xliff';
        $this->file_url = $data['file_url'] ?? '';
        $this->created = $data['created'] ?? false;
    }
}
