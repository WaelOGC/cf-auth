<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Lightweight admin export writers (CSV / XLSX / PDF / DOCX) with no Composer deps.
 * XLSX and DOCX are minimal ZIP+XML packages; PDF is a hand-rolled text table.
 */
class CF_Admin_Export {

    /**
     * @param string               $filename Base filename without extension.
     * @param string               $format   csv|xlsx|pdf|docx
     * @param string[]             $headers
     * @param array<int,string[]>  $rows
     * @param string               $title    Optional document title (PDF/DOCX).
     */
    public static function download( $filename, $format, array $headers, array $rows, $title = '' ) {
        $format   = sanitize_key( $format );
        $filename = sanitize_file_name( $filename );
        if ( $filename === '' ) {
            $filename = 'cf-export';
        }
        if ( $title === '' ) {
            $title = $filename;
        }

        switch ( $format ) {
            case 'csv':
                self::send_csv( $filename . '.csv', $headers, $rows );
                break;
            case 'xlsx':
                self::send_xlsx( $filename . '.xlsx', $headers, $rows );
                break;
            case 'pdf':
                self::send_pdf( $filename . '.pdf', $title, $headers, $rows );
                break;
            case 'docx':
                self::send_docx( $filename . '.docx', $title, $headers, $rows );
                break;
            default:
                wp_die( esc_html__( 'Unknown export format.', 'cf-auth' ), 400 );
        }
        exit;
    }

    /**
     * Build a flat CSV-ready table for the main Active Sessions list.
     *
     * @param array $sessions
     * @return array{0:string[],1:array<int,string[]>}
     */
    public static function sessions_table_rows( array $sessions ) {
        $headers = [ 'Member', 'Email', 'Country', 'IP address' ];
        $rows    = [];
        foreach ( $sessions as $s ) {
            $country = trim(
                ( $s['country_flag'] ?? '' ) . ' ' . ( $s['country_name'] ?? '' )
            );
            if ( $country === '' && ! empty( $s['country_code'] ) ) {
                $country = (string) $s['country_code'];
            }
            $rows[] = [
                (string) ( $s['display_name'] ?? '' ),
                (string) ( $s['email'] ?? '' ),
                trim( $country ),
                (string) ( $s['ip_address'] ?? '' ),
            ];
        }
        return [ $headers, $rows ];
    }

    /**
     * Multi-section export for one Deep Analyst user (or flattened "all users" compare).
     *
     * @param array $detail get_session_detail() payload
     * @return array{0:string[],1:array<int,string[]>}
     */
    public static function session_detail_rows( array $detail ) {
        $headers = [
            'Section', 'Item', 'Minutes', 'Liked', 'Commented', 'Shared',
            'Country', 'City', 'IP', 'Xfinity',
        ];
        $rows = [];

        $yn = static function ( $v ) {
            return $v ? 'Yes' : 'No';
        };

        foreach ( (array) ( $detail['listening']['songs'] ?? [] ) as $song ) {
            $rows[] = [
                'Listening',
                (string) ( $song['title'] ?? '' ),
                (string) ( $song['minutes'] ?? 0 ),
                $yn( ! empty( $song['liked'] ) ),
                $yn( ! empty( $song['commented'] ) ),
                $yn( ! empty( $song['shared'] ) ),
                '',
                '',
                '',
                (string) ( $song['xfinity'] ?? '' ),
            ];
        }
        foreach ( (array) ( $detail['browsing']['pages'] ?? [] ) as $page ) {
            $rows[] = [
                'Browsing',
                (string) ( $page['title'] ?? '' ),
                (string) ( $page['minutes'] ?? 0 ),
                '',
                '',
                '',
                (string) ( $page['country_name'] ?? '' ),
                (string) ( $page['city'] ?? '' ),
                (string) ( $page['ip_address'] ?? '' ),
                '',
            ];
        }
        foreach ( (array) ( $detail['reading']['articles'] ?? [] ) as $article ) {
            $rows[] = [
                'Reading',
                (string) ( $article['title'] ?? '' ),
                (string) ( $article['minutes'] ?? 0 ),
                $yn( ! empty( $article['liked'] ) ),
                $yn( ! empty( $article['commented'] ) ),
                $yn( ! empty( $article['shared'] ) ),
                '',
                '',
                '',
                '',
            ];
        }

        $total = $detail['total'] ?? [];
        $rows[] = [
            'Total',
            'Listening / Browsing / Reading',
            (string) ( $total['minutes'] ?? 0 ),
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ];
        $rows[] = [
            'Xfinity Today',
            'Total earned',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            (string) ( $detail['xfinity']['today'] ?? 0 ),
        ];

        return [ $headers, $rows ];
    }

    /**
     * Comparison rows for all users (Deep Analyst "Export all users").
     *
     * @param array $sessions
     * @return array{0:string[],1:array<int,string[]>}
     */
    public static function all_users_compare_rows( array $sessions ) {
        $headers = [
            'Member', 'Email', 'Country', 'IP address', 'Status',
            'Listening (min)', 'Browsing (min)', 'Reading (min)', 'Total (min)', 'Xfinity today',
        ];
        $rows = [];
        foreach ( $sessions as $s ) {
            $country = trim( ( $s['country_flag'] ?? '' ) . ' ' . ( $s['country_name'] ?? '' ) );
            $live    = ! empty( $s['is_currently_active'] ) || ( $s['status'] ?? '' ) === 'live';
            $rows[]  = [
                (string) ( $s['display_name'] ?? '' ),
                (string) ( $s['email'] ?? '' ),
                trim( $country ),
                (string) ( $s['ip_address'] ?? '' ),
                $live ? 'Live now' : 'Idle',
                (string) (int) ( $s['listening_minutes'] ?? 0 ),
                (string) (int) ( $s['browsing_minutes'] ?? 0 ),
                (string) (int) ( $s['reading_minutes'] ?? 0 ),
                (string) (int) ( $s['total_minutes'] ?? 0 ),
                (string) ( $s['xfinity_today'] ?? '0' ),
            ];
        }
        return [ $headers, $rows ];
    }

    // ── Format writers ────────────────────────────────────────────────────────

    private static function send_csv( $filename, array $headers, array $rows ) {
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $out = fopen( 'php://output', 'w' );
        // UTF-8 BOM for Excel friendliness.
        fwrite( $out, "\xEF\xBB\xBF" );
        fputcsv( $out, $headers );
        foreach ( $rows as $row ) {
            fputcsv( $out, $row );
        }
        fclose( $out );
    }

    private static function send_xlsx( $filename, array $headers, array $rows ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            // Fallback: CSV bytes with .xlsx extension so the download still works.
            // Upgrade path: require ZipArchive / swap in a real XLSX writer.
            self::send_csv( preg_replace( '/\.xlsx$/i', '.csv', $filename ), $headers, $rows );
            return;
        }

        $sheet_rows = array_merge( [ $headers ], $rows );
        $sheet_xml  = self::build_xlsx_sheet( $sheet_rows );

        $tmp = wp_tempnam( 'cf-xlsx-' );
        $zip = new ZipArchive();
        if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
            @unlink( $tmp );
            wp_die( esc_html__( 'Could not create Excel file.', 'cf-auth' ), 500 );
        }

        $zip->addFromString( '[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>'
        );
        $zip->addFromString( '_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>'
        );
        $zip->addFromString( 'xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>'
        );
        $zip->addFromString( 'xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>'
        );
        $zip->addFromString( 'xl/worksheets/sheet1.xml', $sheet_xml );
        $zip->close();

        $bytes = file_get_contents( $tmp );
        @unlink( $tmp );

        nocache_headers();
        header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . strlen( $bytes ) );
        echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * @param array<int,string[]> $sheet_rows
     */
    private static function build_xlsx_sheet( array $sheet_rows ) {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        foreach ( $sheet_rows as $r_idx => $row ) {
            $row_num = $r_idx + 1;
            $xml    .= '<row r="' . $row_num . '">';
            foreach ( array_values( $row ) as $c_idx => $cell ) {
                $col = self::xlsx_col( $c_idx );
                $ref = $col . $row_num;
                $val = self::xml_escape( (string) $cell );
                // Inline string cells (t="inlineStr") — no sharedStrings part needed.
                $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . $val . '</t></is></c>';
            }
            $xml .= '</row>';
        }

        $xml .= '</sheetData></worksheet>';
        return $xml;
    }

    private static function xlsx_col( $index ) {
        $index = (int) $index;
        $col   = '';
        do {
            $col   = chr( 65 + ( $index % 26 ) ) . $col;
            $index = intdiv( $index, 26 ) - 1;
        } while ( $index >= 0 );
        return $col;
    }

    private static function send_docx( $filename, $title, array $headers, array $rows ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            // Fallback: HTML table served as .doc-compatible content with .docx name.
            // Upgrade path: require ZipArchive for a real OOXML package.
            self::send_html_as_download(
                $filename,
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                $title,
                $headers,
                $rows
            );
            return;
        }

        $document_xml = self::build_docx_document( $title, $headers, $rows );
        $tmp = wp_tempnam( 'cf-docx-' );
        $zip = new ZipArchive();
        if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
            @unlink( $tmp );
            wp_die( esc_html__( 'Could not create Word file.', 'cf-auth' ), 500 );
        }

        $zip->addFromString( '[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>'
        );
        $zip->addFromString( '_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>'
        );
        $zip->addFromString( 'word/document.xml', $document_xml );
        $zip->close();

        $bytes = file_get_contents( $tmp );
        @unlink( $tmp );

        nocache_headers();
        header( 'Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . strlen( $bytes ) );
        echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    private static function build_docx_document( $title, array $headers, array $rows ) {
        $body  = '<w:p><w:r><w:t>' . self::xml_escape( $title ) . '</w:t></w:r></w:p>';
        $body .= '<w:tbl>';
        $body .= '<w:tblPr><w:tblW w:w="5000" w:type="pct"/></w:tblPr>';

        $all = array_merge( [ $headers ], $rows );
        foreach ( $all as $row ) {
            $body .= '<w:tr>';
            foreach ( $row as $cell ) {
                $body .= '<w:tc><w:p><w:r><w:t>' . self::xml_escape( (string) $cell ) . '</w:t></w:r></w:p></w:tc>';
            }
            $body .= '</w:tr>';
        }
        $body .= '</w:tbl>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>' . $body . '<w:sectPr/></w:body></w:document>';
    }

    private static function send_pdf( $filename, $title, array $headers, array $rows ) {
        $pdf = self::build_simple_pdf( $title, $headers, $rows );
        nocache_headers();
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . strlen( $pdf ) );
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Minimal multi-page PDF table writer (Helvetica, Latin-1-ish via ASCII fallback).
     */
    private static function build_simple_pdf( $title, array $headers, array $rows ) {
        $lines = [];
        $lines[] = self::pdf_safe( $title );
        $lines[] = str_repeat( '-', 90 );
        $lines[] = self::pdf_safe( implode( ' | ', $headers ) );
        $lines[] = str_repeat( '-', 90 );
        foreach ( $rows as $row ) {
            $lines[] = self::pdf_safe( implode( ' | ', array_map( 'strval', $row ) ) );
        }

        $page_height = 792;
        $page_width  = 612;
        $margin      = 40;
        $line_h      = 12;
        $usable      = $page_height - ( 2 * $margin );
        $per_page    = max( 1, (int) floor( $usable / $line_h ) );

        $pages = array_chunk( $lines, $per_page );
        if ( empty( $pages ) ) {
            $pages = [ [ self::pdf_safe( $title ) ] ];
        }

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $page_ids   = [];
        $next_id    = 3;

        $content_ids = [];
        foreach ( $pages as $page_lines ) {
            $y = $page_height - $margin;
            $stream = "BT\n/F1 9 Tf\n";
            foreach ( $page_lines as $line ) {
                $stream .= sprintf( "1 0 0 1 %d %d Tm (%s) Tj\n", $margin, $y, self::pdf_escape( $line ) );
                $y -= $line_h;
            }
            $stream .= "ET";
            $content_id = $next_id++;
            $objects[ $content_id ] = '<< /Length ' . strlen( $stream ) . " >>\nstream\n" . $stream . "\nendstream";
            $content_ids[] = $content_id;
        }

        $font_id = $next_id++;
        $objects[ $font_id ] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        foreach ( $content_ids as $content_id ) {
            $page_id = $next_id++;
            $page_ids[] = $page_id;
            $objects[ $page_id ] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Contents %d 0 R /Resources << /Font << /F1 %d 0 R >> >> >>',
                $page_width,
                $page_height,
                $content_id,
                $font_id
            );
        }

        $kids = implode( ' ', array_map( static function ( $id ) {
            return $id . ' 0 R';
        }, $page_ids ) );
        $objects[2] = sprintf( '<< /Type /Pages /Kids [%s] /Count %d >>', $kids, count( $page_ids ) );

        ksort( $objects );
        $pdf = "%PDF-1.4\n";
        $offsets = [ 0 ];
        foreach ( $objects as $id => $body ) {
            $offsets[ $id ] = strlen( $pdf );
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref_pos = strlen( $pdf );
        $max_id   = max( array_keys( $objects ) );
        $pdf     .= "xref\n0 " . ( $max_id + 1 ) . "\n";
        $pdf     .= "0000000000 65535 f \n";
        for ( $i = 1; $i <= $max_id; $i++ ) {
            $off  = isset( $offsets[ $i ] ) ? $offsets[ $i ] : 0;
            $pdf .= sprintf( "%010d 00000 n \n", $off );
        }
        $pdf .= "trailer\n<< /Size " . ( $max_id + 1 ) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xref_pos . "\n%%EOF";
        return $pdf;
    }

    private static function send_html_as_download( $filename, $mime, $title, array $headers, array $rows ) {
        $html  = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'
            . esc_html( $title ) . '</title></head><body>';
        $html .= '<h1>' . esc_html( $title ) . '</h1><table border="1" cellpadding="4" cellspacing="0"><thead><tr>';
        foreach ( $headers as $h ) {
            $html .= '<th>' . esc_html( (string) $h ) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ( $rows as $row ) {
            $html .= '<tr>';
            foreach ( $row as $cell ) {
                $html .= '<td>' . esc_html( (string) $cell ) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></body></html>';

        nocache_headers();
        header( 'Content-Type: ' . $mime );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    private static function xml_escape( $str ) {
        return htmlspecialchars( $str, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
    }

    private static function pdf_safe( $str ) {
        // Strip non-Latin1 so Helvetica can render something readable.
        $str = wp_strip_all_tags( (string) $str );
        $str = preg_replace( '/[^\x20-\x7E]/', '?', $str );
        if ( strlen( $str ) > 110 ) {
            $str = substr( $str, 0, 107 ) . '...';
        }
        return $str;
    }

    private static function pdf_escape( $str ) {
        return str_replace( [ '\\', '(', ')' ], [ '\\\\', '\\(', '\\)' ], $str );
    }
}
