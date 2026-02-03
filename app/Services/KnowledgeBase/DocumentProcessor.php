<?php

namespace App\Services\KnowledgeBase;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeBaseEmbedding;
use Illuminate\Http\UploadedFile;
use Smalot\PdfParser\Parser as PdfParser;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;

class DocumentProcessor
{
    protected int $chunkSize = 1000;
    protected int $chunkOverlap = 100;

    /**
     * Extract text content from an uploaded file
     */
    public function extractText(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        return match ($extension) {
            'txt' => $this->extractFromTxt($file),
            'pdf' => $this->extractFromPdf($file),
            'docx', 'doc' => $this->extractFromWord($file),
            'csv' => $this->extractFromCsv($file),
            default => throw new \InvalidArgumentException("Unsupported file type: {$extension}"),
        };
    }

    /**
     * Extract text from TXT file
     */
    protected function extractFromTxt(UploadedFile $file): string
    {
        return file_get_contents($file->getPathname());
    }

    /**
     * Extract text from PDF file
     */
    protected function extractFromPdf(UploadedFile $file): string
    {
        try {
            $parser = new PdfParser();
            $pdf = $parser->parseFile($file->getPathname());
            return $pdf->getText();
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to parse PDF: {$e->getMessage()}");
        }
    }

    /**
     * Extract text from Word document
     */
    protected function extractFromWord(UploadedFile $file): string
    {
        try {
            $phpWord = WordIOFactory::load($file->getPathname());
            $text = '';

            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    $text .= $this->extractTextFromElement($element) . "\n";
                }
            }

            return trim($text);
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to parse Word document: {$e->getMessage()}");
        }
    }

    /**
     * Recursively extract text from Word document elements
     */
    protected function extractTextFromElement($element): string
    {
        $text = '';

        if (method_exists($element, 'getText')) {
            $text .= $element->getText();
        }

        if (method_exists($element, 'getElements')) {
            foreach ($element->getElements() as $child) {
                $text .= $this->extractTextFromElement($child);
            }
        }

        return $text;
    }

    /**
     * Extract text from CSV file
     */
    protected function extractFromCsv(UploadedFile $file): string
    {
        $content = '';
        $handle = fopen($file->getPathname(), 'r');
        
        if ($handle === false) {
            throw new \RuntimeException("Failed to open CSV file");
        }

        $headers = fgetcsv($handle);
        
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) === count($headers)) {
                $combined = array_combine($headers, $row);
                $content .= implode(', ', array_map(
                    fn($key, $value) => "{$key}: {$value}",
                    array_keys($combined),
                    array_values($combined)
                )) . "\n";
            }
        }

        fclose($handle);
        return trim($content);
    }

    /**
     * Create text chunks from knowledge base entry
     */
    public function createChunks(KnowledgeBase $entry): void
    {
        $content = $entry->content;
        
        if (empty($content)) {
            return;
        }

        // Clean the content
        $content = $this->cleanText($content);
        
        // Split into chunks
        $chunks = $this->splitIntoChunks($content);

        // Create embedding records
        foreach ($chunks as $index => $chunkText) {
            KnowledgeBaseEmbedding::create([
                'knowledge_base_id' => $entry->id,
                'chunk_text' => $chunkText,
                'chunk_index' => $index,
                'embedding_data' => null, // TODO: Generate actual embeddings with AI provider
            ]);
        }
    }

    /**
     * Clean text content
     */
    protected function cleanText(string $text): string
    {
        // Remove excessive whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Remove null bytes
        $text = str_replace("\0", '', $text);
        
        // Normalize line endings
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        
        return trim($text);
    }

    /**
     * Split text into overlapping chunks
     */
    protected function splitIntoChunks(string $text): array
    {
        $chunks = [];
        $sentences = $this->splitIntoSentences($text);
        
        $currentChunk = '';
        $currentLength = 0;

        foreach ($sentences as $sentence) {
            $sentenceLength = strlen($sentence);
            
            // If adding this sentence exceeds chunk size, save current chunk
            if ($currentLength + $sentenceLength > $this->chunkSize && $currentLength > 0) {
                $chunks[] = trim($currentChunk);
                
                // Start new chunk with overlap
                $words = explode(' ', $currentChunk);
                $overlapWords = array_slice($words, -($this->chunkOverlap / 5)); // Approximate word count for overlap
                $currentChunk = implode(' ', $overlapWords) . ' ' . $sentence;
                $currentLength = strlen($currentChunk);
            } else {
                $currentChunk .= ' ' . $sentence;
                $currentLength += $sentenceLength;
            }
        }

        // Add final chunk
        if (trim($currentChunk)) {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }

    /**
     * Split text into sentences
     */
    protected function splitIntoSentences(string $text): array
    {
        // Split on sentence boundaries
        $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        
        return array_filter($sentences, fn($s) => trim($s) !== '');
    }

    /**
     * Set chunk size
     */
    public function setChunkSize(int $size): self
    {
        $this->chunkSize = $size;
        return $this;
    }

    /**
     * Set chunk overlap
     */
    public function setChunkOverlap(int $overlap): self
    {
        $this->chunkOverlap = $overlap;
        return $this;
    }
}
