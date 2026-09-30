from __future__ import annotations

import base64
import html
import mimetypes
import re
from pathlib import Path


ROOT = Path(__file__).resolve().parent
OUTPUT = ROOT / "corrected-chapters-1-5.html"
CHAPTERS = [
    "chapter-1.md",
    "chapter-2.md",
    "chapter-3.md",
    "chapter-4.md",
    "chapter-5.md",
]
PRELIMINARY_FILE = "preliminary-pages.md"
PRELIMINARY_SECTIONS = [
    "Declaration",
    "Certification",
    "Dedication",
    "Acknowledgments",
    "Abstract",
    "Table of Contents",
    "List of Tables",
    "List of Figures",
    "List of Listings",
    "List of Abbreviations",
]

CONTENTS_PAGE_NUMBERS = {
    "Preliminary Pages": "i", "Title Page": "i", "Declaration": "ii",
    "Certification": "iii", "Dedication": "iv", "Acknowledgments": "v",
    "Abstract": "vi", "Table of Contents": "viii", "List of Tables": "x",
    "List of Figures": "xi", "List of Listings": "xiii",
    "List of Abbreviations": "xiv", "Chapter One: Introduction": "1",
    "1.1 Background to the Study": "1", "1.2 Statement of the Problem": "2",
    "1.3 Aim of the Study": "4", "1.4 Objectives of the Study": "4",
    "1.5 Research Questions": "5", "1.7 Significance of the Study": "5",
    "1.8 Scope of the Study": "6", "1.9 Limitations of the Study": "7",
    "1.10 Definition of Terms": "7", "1.11 Organisation of the Report": "8",
    "Chapter Two: Literature Review": "9", "2.1 Introduction to the Chapter": "9",
    "2.2 Conceptual Review": "9", "2.3 Theoretical Framework": "13",
    "2.4 Empirical Review of Related Works": "14",
    "2.5 Review of Existing Systems/Tools": "16",
    "2.6 Comparative Analysis of Related Works": "17",
    "2.7 Identified Research Gap": "18", "2.8 Summary of the Chapter": "19",
    "Chapter Three: Methodology": "20", "3.1 Introduction to the Chapter": "20",
    "3.2 Research Design / Project Approach": "20",
    "3.3 Analysis of Existing System": "21", "3.4 Proposed System Overview": "22",
    "3.5 System Requirements": "24", "3.6 Data Collection Methods": "28",
    "3.7 Population and Sampling": "28", "3.8 System Architecture / Design": "29",
    "3.9 Use Case / UML Diagrams": "30", "3.10 Database Design": "33",
    "3.11 Algorithm / Model Design": "36", "3.12 Tools and Technologies": "37",
    "3.13 Ethical Considerations": "39", "3.14 Summary of the Chapter": "40",
    "Chapter Four: System Implementation and Testing": "41",
    "4.1 Implementation of the System Design": "41",
    "4.2 Module Integration and Coding": "46",
    "4.3 Testing Strategy and Procedures": "73",
    "4.4 Test Results and Discussion": "83",
    "Chapter Five: Summary, Conclusion and Recommendations": "90",
    "5.1 Introduction": "90", "5.2 Summary of Findings": "90",
    "5.3 Achievement of Objectives": "92", "5.4 Contributions of the Study": "94",
    "5.5 Conclusion": "95", "5.6 Limitations": "95", "5.7 Recommendations": "96",
    "5.8 Future Work": "97", "5.9 AI-Assistance Disclosure": "98",
    "References": "99",
}

LISTING_PAGE_NUMBERS = {
    "4.1": "47", "4.2": "47", "4.3": "48",
    "4.4": "49", "4.5": "52", "4.6": "53",
}

TITLE_LINES = [
    "MIVA OPEN UNIVERSITY",
    "FACULTY OF COMPUTING",
    "DEPARTMENT OF SOFTWARE ENGINEERING",
    "DESIGN AND IMPLEMENTATION OF AN AI-ENHANCED MULTI-TENANT FAMILY FUND MANAGEMENT SYSTEM WITH PREDICTIVE ANALYTICS AND INTELLIGENT REPORTING",
    "BY",
    "AMINU DANLADI HUSSAIN",
    "2024/A/SENG/0156",
    "A PROJECT SUBMITTED TO THE DEPARTMENT OF SOFTWARE ENGINEERING, FACULTY OF COMPUTING, MIVA OPEN UNIVERSITY, IN PARTIAL FULFILMENT OF THE REQUIREMENTS FOR THE AWARD OF THE DEGREE OF BACHELOR OF SCIENCE (B.Sc.) IN SOFTWARE ENGINEERING",
    "SUPERVISOR: DR. AYODEJI SAMUEL MAKINDE",
    "MAY, 2026",
]


def parse_inline_markdown(value: str) -> str:
    value = html.unescape(value)
    value = re.sub(r"\[([^\]]+)\]\([^)]+\)", r"\1", value)
    value = re.sub(r"</?[^>]+>", "", value)
    value = value.replace(r"\_", "_").replace(r"\[", "[").replace(r"\]", "]")
    return value.replace("**", "").replace("*", "").replace("`", "")


def split_table_row(line: str) -> list[str]:
    return [parse_inline_markdown(cell.strip()) for cell in line.strip().strip("|").split("|")]


def render_table(lines: list[str], caption: str | None = None) -> str:
    rows = [split_table_row(line) for index, line in enumerate(lines) if index != 1]
    header = ""
    body_rows = []
    for row_index, row in enumerate(rows):
        tag = "th" if row_index == 0 else "td"
        cells = "".join(f"<{tag}>{html.escape(cell)}</{tag}>" for cell in row)
        if row_index == 0:
            header = f"<thead><tr>{cells}</tr></thead>"
        else:
            body_rows.append(f"<tr>{cells}</tr>")
    is_wide = bool(rows and len(rows[0]) >= 7)
    css_class = ' class="wide-table"' if is_wide else ""
    block_class = "table-block wide-table-block" if is_wide else "table-block"
    caption_html = f'<p class="table-caption">{html.escape(caption)}</p>' if caption else ""

    return (
        f'<section class="{block_class}">{caption_html}<table{css_class}>'
        + header
        + "<tbody>"
        + "".join(body_rows)
        + "</tbody></table></section>"
    )


def image_data_uri(path: Path) -> str | None:
    if not path.exists():
        return None
    mime_type = mimetypes.guess_type(path.name)[0] or "image/png"
    encoded = base64.b64encode(path.read_bytes()).decode("ascii")
    return f"data:{mime_type};base64,{encoded}"


def render_image(caption: str, relative_path: str) -> str:
    data_uri = image_data_uri(ROOT / relative_path)
    caption_html = f'<p class="figure-caption">{html.escape(caption)}</p>'
    if data_uri is None:
        return caption_html + f'<p class="missing-image">Image missing: {html.escape(relative_path)}</p>'
    return caption_html + f'<p class="image-wrap"><img src="{data_uri}" alt="{html.escape(caption)}"></p>'


def render_markdown_file(file_name: str) -> str:
    lines = (ROOT / file_name).read_text().splitlines()
    output: list[str] = []
    pending_table: list[str] = []
    pending_list: list[str] = []
    pending_table_caption: str | None = None
    code_lines: list[str] = []
    in_code_block = False

    def flush_table() -> None:
        nonlocal pending_table, pending_table_caption
        if pending_table:
            output.append(render_table(pending_table, pending_table_caption))
            pending_table = []
            pending_table_caption = None

    def flush_list() -> None:
        nonlocal pending_list
        if pending_list:
            items = "".join(f"<li>{html.escape(item)}</li>" for item in pending_list)
            output.append(f"<ol>{items}</ol>")
            pending_list = []

    for raw_line in lines:
        line = raw_line.rstrip()
        stripped = line.strip()

        if stripped.startswith("```"):
            flush_table()
            flush_list()
            if in_code_block:
                output.append(f"<pre><code>{html.escape(chr(10).join(code_lines))}</code></pre>")
                code_lines = []
                in_code_block = False
            else:
                in_code_block = True
            continue

        if in_code_block:
            code_lines.append(line)
            continue

        if pending_table and stripped.startswith("|"):
            pending_table.append(stripped)
            continue
        flush_table()

        if not stripped or stripped == "---" or stripped.startswith("> **References:**"):
            flush_list()
            continue

        if stripped.startswith("|"):
            flush_list()
            pending_table.append(stripped)
            continue

        image_match = re.match(r"!\[(.+?)\]\((.+?)\)", stripped)
        if image_match:
            flush_list()
            caption, relative_path = image_match.groups()
            output.append(render_image(caption, relative_path))
            continue

        table_caption = re.match(r"\*Table ([^*]+)\*", stripped)
        if table_caption:
            flush_list()
            pending_table_caption = f"Table {table_caption.group(1)}"
            continue

        listing_caption = re.match(r"\*Listing ([^*]+)\*", stripped)
        if listing_caption:
            flush_list()
            output.append(f'<p class="listing-caption">Listing {html.escape(listing_caption.group(1))}</p>')
            continue

        numbered = re.match(r"^(\d+)\.\s+(.+)$", stripped)
        if numbered:
            pending_list.append(parse_inline_markdown(numbered.group(2)))
            continue
        flush_list()

        if stripped.startswith("# "):
            output.append(f"<h1>{html.escape(stripped[2:].strip())}</h1>")
        elif stripped.startswith("## "):
            output.append(f"<h2>{html.escape(stripped[3:].strip())}</h2>")
        elif stripped.startswith("### "):
            output.append(f"<h3>{html.escape(stripped[4:].strip())}</h3>")
        elif stripped.startswith("#### "):
            output.append(f"<h4>{html.escape(stripped[5:].strip())}</h4>")
        else:
            output.append(f"<p>{html.escape(parse_inline_markdown(stripped))}</p>")

    flush_table()
    flush_list()
    if code_lines:
        output.append(f"<pre><code>{html.escape(chr(10).join(code_lines))}</code></pre>")
    return "\n".join(output)


def render_references() -> str:
    output = ["<div class=\"page-break\"></div>", "<h1>REFERENCES</h1>"]
    for line in (ROOT / "references.md").read_text().splitlines():
        stripped = line.strip()
        if not stripped or stripped.startswith("#") or stripped.startswith(">") or stripped == "---":
            continue
        output.append(f'<p class="reference">{html.escape(parse_inline_markdown(stripped))}</p>')
    return "\n".join(output)


def preliminary_sections() -> dict[str, list[str]]:
    sections: dict[str, list[str]] = {}
    current: str | None = None

    for line in (ROOT / PRELIMINARY_FILE).read_text(encoding="utf-8").splitlines():
        if line.startswith("## "):
            current = line[3:].strip()
            sections[current] = []
            continue
        if current is not None:
            sections[current].append(line)

    missing = [section for section in PRELIMINARY_SECTIONS if section not in sections]
    if missing:
        raise ValueError(f"Missing preliminary sections: {', '.join(missing)}")

    return sections


def render_list_of_listings_table() -> str:
    header = "<thead><tr><th>Listing No.</th><th>Title</th><th>Page</th></tr></thead>"
    rows = []
    pattern = re.compile(r"\*Listing (\d+\.\d+):\s*([^*]+)\*")

    for chapter in CHAPTERS:
        for line in (ROOT / chapter).read_text().splitlines():
            match = pattern.fullmatch(line.strip())
            if match:
                rows.append(
                    "<tr>"
                    f"<td>{html.escape(match.group(1))}</td>"
                    f"<td>{html.escape(match.group(2))}</td>"
                    f"<td>{LISTING_PAGE_NUMBERS[match.group(1)]}</td>"
                    "</tr>",
                )

    return '<section class="table-block"><table>' + header + "<tbody>" + "".join(rows) + "</tbody></table></section>"


def render_certification(lines: list[str]) -> str:
    narrative = next(
        (
            parse_inline_markdown(line.strip())
            for line in lines
            if line.strip()
            and line.strip() != "---"
            and not line.strip().startswith("<")
        ),
        "",
    )
    rows = [
        ["Dr. Ayodeji Samuel Makinde", "______________", "______________"],
        ["Supervisor", "Signature", "Date"],
        ["______________", "______________", "______________"],
        ["Head of Department", "Signature", "Date"],
        ["______________", "______________", "______________"],
        ["External Examiner", "Signature", "Date"],
    ]
    table_rows = "".join(
        "<tr>" + "".join(f"<td>{html.escape(cell)}</td>" for cell in row) + "</tr>"
        for row in rows
    )
    narrative_html = f"<p>{html.escape(narrative)}</p>" if narrative else ""

    return narrative_html + f'<table class="signature-table"><tbody>{table_rows}</tbody></table>'


def render_contents(lines: list[str]) -> str:
    output = []

    for raw_line in lines:
        match = re.match(r"^(\s*)-\s+(.+)$", raw_line)
        if match is None:
            continue
        depth = min(3, len(match.group(1)) // 2)
        text = parse_inline_markdown(match.group(2))
        page = CONTENTS_PAGE_NUMBERS[text]
        output.append(
            f'<p class="toc-entry toc-depth-{depth}">'
            f'<span>{html.escape(text)}</span><span class="toc-page">{page}</span></p>',
        )

    return "\n".join(output)


def render_preliminary_body(lines: list[str]) -> str:
    output: list[str] = []
    pending_table: list[str] = []
    pending_bullets: list[str] = []

    def flush_table() -> None:
        nonlocal pending_table
        if pending_table:
            output.append(render_table(pending_table))
            pending_table = []

    def flush_bullets() -> None:
        nonlocal pending_bullets
        if pending_bullets:
            items = "".join(f"<li>{html.escape(item)}</li>" for item in pending_bullets)
            output.append(f"<ul>{items}</ul>")
            pending_bullets = []

    for raw_line in lines:
        stripped = raw_line.strip()

        if pending_table and stripped.startswith("|"):
            pending_table.append(stripped)
            continue
        flush_table()

        if not stripped or stripped == "---" or stripped in {"<br/>", '<div align="center">', "</div>"}:
            flush_bullets()
            continue
        if stripped.startswith("|"):
            flush_bullets()
            pending_table.append(stripped)
            continue

        bullet = re.match(r"^-\s+(.+)$", stripped)
        if bullet:
            pending_bullets.append(parse_inline_markdown(bullet.group(1)))
            continue
        flush_bullets()

        text = html.escape(parse_inline_markdown(stripped))
        css_class = "front-note" if stripped.startswith("*") and stripped.endswith("*") else ""
        if stripped.startswith("**Keywords:**"):
            css_class = "front-keywords"
        class_attribute = f' class="{css_class}"' if css_class else ""
        output.append(f"<p{class_attribute}>{text}</p>")

    flush_table()
    flush_bullets()
    return "\n".join(output)


def render_preliminary_pages() -> str:
    sections = preliminary_sections()
    output = []

    for title in PRELIMINARY_SECTIONS:
        output.append('<section class="front-section">')
        output.append(f"<h1>{html.escape(title.upper())}</h1>")
        lines = sections[title]

        if title == "Certification":
            output.append(render_certification(lines))
        elif title == "Table of Contents":
            output.append(render_contents(lines))
        elif title == "List of Listings":
            output.append(render_list_of_listings_table())
            for note in lines:
                if note.strip().startswith("*") and not note.strip().startswith("| "):
                    output.append(f'<p class="front-note">{html.escape(parse_inline_markdown(note.strip()))}</p>')
        else:
            output.append(render_preliminary_body(lines))

        output.append("</section>")

    return "\n".join(output)


def main() -> None:
    title_lines = "\n".join(f"<p>{html.escape(line)}</p>" for line in TITLE_LINES)
    chapters = [
        '<section class="title-page">',
        title_lines,
        "</section>",
        render_preliminary_pages(),
    ]
    for index, chapter in enumerate(CHAPTERS):
        if index > 0:
            chapters.append('<div class="page-break"></div>')
        chapters.append(render_markdown_file(chapter))
    chapters.append(render_references())

    document = f"""<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Corrected Chapters 1-5</title>
<link rel="icon" href="data:,">
<style>
@page {{
    size: A4 portrait;
    margin: 1in;
}}
@page wide {{
    size: A4 landscape;
    margin: 1in;
}}
body {{
    box-sizing: border-box;
    color: #000;
    font-family: "Times New Roman", Times, serif;
    font-size: 12pt;
    line-height: 1.5;
    max-width: 6.27in;
    margin: 0 auto;
    padding: 0.6in 0.3in;
}}
p {{
    margin: 0 0 8pt;
    text-align: justify;
}}
h1, h2 {{
    color: #000;
    font-weight: 700;
    margin: 18pt 0 8pt;
    text-align: center;
}}
h1 {{ font-size: 16pt; }}
h2 {{ font-size: 14pt; }}
h3, h4 {{
    color: #000;
    font-size: 12pt;
    font-weight: 700;
    margin: 12pt 0 6pt;
}}
ol {{
    margin: 0 0 8pt 0.35in;
    padding-left: 0.2in;
}}
ul {{
    margin: 0 0 8pt 0.35in;
    padding-left: 0.2in;
}}
li {{
    margin-bottom: 5pt;
    text-align: justify;
}}
table {{
    border-collapse: collapse;
    color: #000;
    font-size: 10.5pt;
    margin: 8pt 0 14pt;
    width: 100%;
}}
th, td {{
    border: 1px solid #000;
    padding: 5pt 6pt;
    text-align: left;
    vertical-align: top;
}}
th {{
    font-weight: 700;
}}
thead {{
    display: table-header-group;
}}
.title-page {{
    font-weight: 700;
    page-break-after: always;
}}
.title-page p {{
    margin-bottom: 14pt;
    text-align: center;
}}
.front-section {{
    break-after: page;
    page-break-after: always;
}}
.front-note {{
    font-style: italic;
}}
.front-keywords {{
    font-weight: 700;
}}
.toc-entry {{
    align-items: baseline;
    display: flex;
    gap: 0.25em;
    margin-bottom: 0;
    text-align: left;
}}
.toc-entry > span:first-child {{
    align-items: baseline;
    display: flex;
    flex: 1;
}}
.toc-entry > span:first-child::after {{
    border-bottom: 1px dotted #000;
    content: "";
    flex: 1;
    margin: 0 0.2em 0.28em;
}}
.toc-page {{ white-space: nowrap; }}
.toc-depth-0 {{
    font-weight: 700;
    margin-bottom: 3pt;
}}
.toc-depth-1 {{ margin-left: 0.28in; }}
.toc-depth-2 {{ margin-left: 0.56in; }}
.toc-depth-3 {{ margin-left: 0.84in; }}
.signature-table td {{
    border: 0;
    padding: 7pt 5pt;
    text-align: center;
    vertical-align: middle;
}}
.table-caption, .figure-caption, .listing-caption {{
    font-weight: 700;
    margin-top: 14pt;
    text-align: center;
}}
.table-caption, .figure-caption, .listing-caption {{
    break-after: avoid;
    page-break-after: avoid;
}}
.table-block {{
    break-inside: auto;
}}
.wide-table-block {{
    page: wide;
    break-before: page;
    break-after: page;
    margin-left: -1.67in;
    width: 9.61in;
}}
.wide-table {{
    font-size: 9pt;
}}
pre {{
    border: 1px solid #000;
    break-inside: avoid;
    font-family: "Courier New", Courier, monospace;
    font-size: 10pt;
    line-height: 1;
    margin: 3pt 0 10pt;
    overflow-wrap: anywhere;
    padding: 8pt;
    white-space: pre-wrap;
}}
.listing-caption + pre {{
    margin-top: 0;
}}
.image-wrap {{
    text-align: center;
    break-inside: avoid;
    page-break-inside: avoid;
}}
img {{
    display: inline-block;
    max-width: 100%;
}}
.reference {{
    text-align: left;
}}
.missing-image {{
    font-style: italic;
    text-align: center;
}}
.page-break {{
    break-before: page;
    page-break-before: always;
}}
@media screen and (max-width: 52rem) {{
    body {{
        max-width: none;
    }}
    .table-block {{
        max-width: 100%;
        overflow-x: auto;
    }}
    .wide-table-block {{
        margin-left: 0;
        width: 100%;
    }}
    .wide-table {{
        min-width: 9.61in;
    }}
    .front-section, .reference {{
        overflow-wrap: anywhere;
    }}
}}
</style>
</head>
<body>
{chr(10).join(chapters)}
</body>
</html>
"""
    OUTPUT.write_text(document)
    print(OUTPUT)


if __name__ == "__main__":
    main()
