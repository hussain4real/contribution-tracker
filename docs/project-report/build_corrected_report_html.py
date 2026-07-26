from __future__ import annotations

import base64
import html
import mimetypes
import re
from pathlib import Path


ROOT = Path(__file__).resolve().parent
OUTPUT = ROOT / "corrected-chapters-1-3.html"

TITLE_LINES = [
    "MIVA OPEN UNIVERSITY",
    "FACULTY OF COMPUTING",
    "DEPARTMENT OF SOFTWARE ENGINEERING",
    "DESIGN AND IMPLEMENTATION OF AN AI-ENHANCED MULTI-TENANT FAMILY FUND MANAGEMENT SYSTEM WITH PREDICTIVE ANALYTICS AND INTELLIGENT REPORTING",
    "BY",
    "AMINU DANLADI HUSSAIN",
    "2024/A/SENG/0156",
    "A PROJECT SUBMITTED TO THE DEPARTMENT OF SOFTWARE ENGINEERING, FACULTY OF COMPUTING, MIVA OPEN UNIVERSITY, IN PARTIAL FULFILMENT OF THE REQUIREMENTS FOR THE AWARD OF THE DEGREE OF BACHELOR OF SCIENCE (B.Sc.) IN SOFTWARE ENGINEERING",
    "SUPERVISOR: DR SAMUEL MAKINDE",
    "MAY, 2026",
]


def parse_inline_markdown(value: str) -> str:
    value = re.sub(r"\[([^\]]+)\]\([^)]+\)", r"\1", value)
    return value.replace("**", "").replace("*", "").replace("`", "")


def split_table_row(line: str) -> list[str]:
    return [parse_inline_markdown(cell.strip()) for cell in line.strip().strip("|").split("|")]


def render_table(lines: list[str]) -> str:
    rows = [split_table_row(line) for index, line in enumerate(lines) if index != 1]
    table_rows = []
    for row_index, row in enumerate(rows):
        tag = "th" if row_index == 0 else "td"
        cells = "".join(f"<{tag}>{html.escape(cell)}</{tag}>" for cell in row)
        table_rows.append(f"<tr>{cells}</tr>")
    return "<table>" + "".join(table_rows) + "</table>"


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

    def flush_table() -> None:
        nonlocal pending_table
        if pending_table:
            output.append(render_table(pending_table))
            pending_table = []

    def flush_list() -> None:
        nonlocal pending_list
        if pending_list:
            items = "".join(f"<li>{html.escape(item)}</li>" for item in pending_list)
            output.append(f"<ol>{items}</ol>")
            pending_list = []

    for raw_line in lines:
        line = raw_line.rstrip()
        stripped = line.strip()

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
            output.append(f'<p class="table-caption">Table {html.escape(table_caption.group(1))}</p>')
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
    return "\n".join(output)


def render_references() -> str:
    output = ["<div class=\"page-break\"></div>", "<h1>REFERENCES</h1>"]
    for line in (ROOT / "references.md").read_text().splitlines():
        stripped = line.strip()
        if not stripped or stripped.startswith("#") or stripped.startswith(">") or stripped == "---":
            continue
        output.append(f'<p class="reference">{html.escape(parse_inline_markdown(stripped))}</p>')
    return "\n".join(output)


def main() -> None:
    title_lines = "\n".join(f"<p>{html.escape(line)}</p>" for line in TITLE_LINES)
    chapters = ['<section class="title-page">', title_lines, "</section>"]
    for index, chapter in enumerate(["chapter-1.md", "chapter-2.md", "chapter-3.md"]):
        if index > 0:
            chapters.append('<div class="page-break"></div>')
        chapters.append(render_markdown_file(chapter))
    chapters.append(render_references())

    document = f"""<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Corrected Chapters 1-3</title>
<style>
body {{
    color: #000;
    font-family: "Times New Roman", Times, serif;
    font-size: 12pt;
    line-height: 1.5;
    max-width: 7.2in;
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
.title-page {{
    font-weight: 700;
    page-break-after: always;
}}
.title-page p {{
    margin-bottom: 14pt;
    text-align: center;
}}
.table-caption, .figure-caption {{
    font-weight: 700;
    margin-top: 14pt;
    text-align: center;
}}
.image-wrap {{
    text-align: center;
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
