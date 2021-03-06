<?php


namespace M4bTool\Parser;


use Exception;
use M4bTool\Audio\Chapter;
use M4bTool\Audio\Tag;
use M4bTool\StringUtilities\Runes;
use M4bTool\StringUtilities\Scanner;
use M4bTool\StringUtilities\Strings;
use Sandreas\Time\TimeUnit;


class FfmetaDataParser
{

    const PARSE_SKIP = 0;
    const PARSE_METADATA = 1;
    const PARSE_CHAPTERS = 2;


    const METADATA_MARKER = ";ffmetadata1";
    const CHAPTER_MARKER = "[chapter]";

    const CODEC_MP3 = "mp3";
    const CODEC_AAC = "aac";
    const CODEC_ALAC = "alac";


    const FORMAT_MP4 = "mp4";
    const FORMAT_MP3 = "mp3";


    const CHANNELS_MONO = 1;
    const CHANNELS_STEREO = 2;

    const CODEC_MAPPING = [
        "aac" => self::CODEC_AAC,
        "mp3" => self::CODEC_MP3,
        "alac" => self::CODEC_ALAC,
    ];
    const FORMAT_MAPPING = [
        "mp4a" => self::FORMAT_MP4,
        "mp3" => self::FORMAT_MP3,
    ];

    const CHANNEL_MAPPING = [
        "mono" => self::CHANNELS_MONO,
        "stereo" => self::CHANNELS_STEREO,
    ];


    protected $scanner;
    protected $lines = [];
    protected $metaDataProperties = [];
    protected $chapters = [];

    protected $duration;
    protected $format;
    protected $codec;
    protected $channels;


    public function __construct(Scanner $scanner = null)
    {
        $this->scanner = $scanner ?? new Scanner;
    }


    /**
     * @param $metaData
     * @param string $streamInfo
     * @throws Exception
     */
    public function parse($metaData, $streamInfo = "")
    {
        $this->reset();
        $this->parseMetaData($metaData);
        if ($streamInfo !== "") {
            $this->parseStreamInfo($streamInfo);
        }
    }

    private function reset()
    {
        $this->metaDataProperties = [];
        $this->chapters = [];

    }


    /**
     * @param $streamInfo
     * @throws Exception
     */
    private function parseStreamInfo($streamInfo)
    {

        $this->scanner->initialize(new Runes($streamInfo));

        while ($this->scanner->scanLine()) {
            $line = $this->scanner->getResult();
            if (stripos($line, "Stream #") !== false && stripos($line, "Audio: ") !== false) {
                $this->parseAudioStream($line);
                continue;
            }

            if (stripos($line, "frame=") !== false && stripos($line, "time=") !== false && $this->duration !== null) {
                $this->parseDuration($line);
                continue;
            }

            // Metadata duration (less exact but sometimes the only information available)
            if (preg_match("/^[\s]+Duration:[\s]+([0-9:\.]+)/", $line, $matches)) {
                $this->parseDurationMatches($matches);
                continue;
            }
        }

        // look for:
        // #<stream-number>(<language>): <type>: <codec>
        // Stream #0:0(und): Audio: aac (LC) (mp4a / 0x6134706D), 44100 Hz, stereo, fltp, 127 kb/s (default)
        // frame=    1 fps=0.0 q=-0.0 Lsize=N/A time=00:00:22.12 bitrate=N/A speed= 360x
    }

    private function parseAudioStream($lineWithStream)
    {
        // Stream #0:0(und): Audio: aac (LC) (mp4a / 0x6134706D), 44100 Hz, stereo, fltp, 127 kb/s (default)
        // Stream #0:0: Audio: mp3, 44100 Hz, stereo, fltp, 128 kb/s

        $parts = explode("Audio: ", $lineWithStream);
        if (count($parts) != 2) {
            return;
        }

        $stream = $parts[1];
        $streamParts = explode(", ", $stream);

        $this->applyAudioStreamMapping(static::CODEC_MAPPING, $streamParts[0], $this->codec);
        $this->applyAudioStreamMapping(static::FORMAT_MAPPING, $streamParts[0], $this->format);
        $this->applyAudioStreamMapping(static::CHANNEL_MAPPING, $streamParts[2], $this->channels);


    }

    private function applyAudioStreamMapping($mapping, &$haystack, &$property)
    {
        foreach ($mapping as $needle => $result) {
            if (stripos($haystack, $needle) !== false) {
                $property = $result;
                break;
            }
        }
    }

    /**
     * @param $lineWithDuration
     * @throws Exception
     */
    private function parseDuration($lineWithDuration)
    {
        // frame=    1 fps=0.0 q=-0.0 Lsize=N/A time=00:00:22.12 bitrate=N/A speed= 360x
        $lastPos = strripos($lineWithDuration, "time=");
        $lastPart = substr($lineWithDuration, $lastPos);

        preg_match("/time=([^\s]+)/", $lastPart, $matches);
        $this->parseDurationMatches($matches);
    }

    /**
     * @param $matches
     * @throws Exception
     */
    private function parseDurationMatches($matches)
    {
        if (!isset($matches[1])) {
            return;
        }

        $this->duration = TimeUnit::fromFormat($matches[1], TimeUnit::FORMAT_DEFAULT);
    }


    private function parseMetaData($metaData)
    {
        $this->scanner->initialize(new Runes($metaData));


        $currentChapter = null;
        $parsingMode = static::PARSE_SKIP;
        while ($this->scanner->scanLine()) {
            $line = $this->scanner->getTrimmedResult();
            $lineString = mb_strtolower($line);

            if ($lineString === static::METADATA_MARKER) {
                $parsingMode = static::PARSE_METADATA;
                continue;
            }

            if ($parsingMode === static::PARSE_SKIP) {
                continue;
            }

            // handle multiline properties (e.g. description)
            while (Strings::hasSuffix($line, "\\")) {
                $this->scanner->scanLine();
                $line = Strings::trimSuffix($line, "\\") . Runes::LINE_FEED . $this->scanner->getTrimmedResult();
            }

            if (Strings::hasPrefix($line, ";")) {
                continue;
            }


            if ($lineString === static::CHAPTER_MARKER) {
                $this->handleChapters();
                break;
            }


            // something fishy in here
            $lineScanner = new Scanner(new Runes((string)$line));
            if (!$lineScanner->scanForward("=")) {
                continue;
            }
            $propertyName = mb_strtolower((string)$lineScanner->getTrimmedResult());
            $lineScanner->scanToEnd();
            $propertyValue = (string)$lineScanner->getTrimmedResult();


            if ($propertyName) {
                $this->metaDataProperties[$propertyName] = $propertyValue;
            }

        }


    }

    private function handleChapters()
    {
        $chapterProperties = [];

        while ($this->scanner->scanLine()) {
            $line = $this->scanner->getTrimmedResult();
            $lineString = mb_strtolower($line);
            if ($lineString === static::CHAPTER_MARKER) {
                $this->createChapter($chapterProperties);
                $chapterProperties = [];
                continue;
            }

            $lineScanner = new Scanner($line);
            if (!$lineScanner->scanForward("=")) {
                continue;
            }

            $propertyName = mb_strtolower((string)$lineScanner->getTrimmedResult());

            if ($propertyName === "") {
                continue;
            }
            $lineScanner->scanToEnd();
            $propertyValue = $lineScanner->getTrimmedResult();

            $chapterProperties[$propertyName] = $propertyValue;
        }

        if (count($chapterProperties) > 0) {
            $this->createChapter($chapterProperties);
        }
    }

    /**
     * @param $chapterProperties
     * @return bool
     */
    private function createChapter($chapterProperties)
    {
        if (!isset($chapterProperties["start"], $chapterProperties["end"], $chapterProperties["timebase"])) {
            return false;
        }
        $timeBaseScanner = new Scanner($chapterProperties["timebase"]);
        if (!$timeBaseScanner->scanForward("/")) {
            return false;
        }
        $timeBaseScanner->scanToEnd();
        $timeBase = (string)$timeBaseScanner->getTrimmedResult();
        $timeUnit = (int)$timeBase / 1000;

        $start = (int)(string)$chapterProperties["start"];
        $end = (int)(string)$chapterProperties["end"];
        $title = $chapterProperties["title"] ?? "";
        $start = new TimeUnit($start, $timeUnit);
        $end = new TimeUnit($end, $timeUnit);
        $length = new TimeUnit($end->milliseconds() - $start->milliseconds());


        $this->chapters[] = new Chapter($start, $length, (string)$title);
        return true;
    }

    public function getChapters()
    {
        return $this->chapters;
    }

    /**
     * @return TimeUnit
     */
    public function getDuration()
    {
        return $this->duration;
    }

    /**
     * @return string
     */
    public function getFormat()
    {
        return $this->format;
    }

    public function toTag()
    {
        $tag = new Tag();
        $tag->album = $this->getProperty("album");
        $tag->sortAlbum = $this->getProperty("sort_album");
        $tag->sortTitle = $this->getProperty("sort_name");
        $tag->sortArtist = $this->getProperty("sort_artist");
        $tag->writer = $this->getProperty("writer") ?? $this->getProperty("composer");
        $tag->genre = $this->getProperty("genre");
        $tag->copyright = $this->getProperty("copyright");
        $tag->encodedBy = $this->getProperty("encoded_by");
        $tag->title = $this->getProperty("title");
        $tag->language = $this->getProperty("language");
        $tag->artist = $this->getProperty("artist");
        $tag->albumArtist = $this->getProperty("album_artist");
        $tag->performer = $this->getProperty("performer");
        $tag->disk = $this->getProperty("disc");
        $tag->publisher = $this->getProperty("publisher");
        $tag->track = $this->getProperty("track");
        $tag->encoder = $this->getProperty("encoder");
        $tag->lyrics = $this->getProperty("lyrics");
        $tag->year = $this->getProperty("date");
        $tag->description = $this->getProperty("description");
        $tag->longDescription = $this->getProperty("longdesc") ?? $this->getProperty("synopsis");

        return $tag;
    }

    public function getProperty($propertyName)
    {
        if (!isset($this->metaDataProperties[$propertyName])) {
            return null;
        }
        return $this->metaDataProperties[$propertyName];
    }
}