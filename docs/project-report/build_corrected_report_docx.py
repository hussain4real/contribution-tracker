from __future__ import annotations

import html
import re
import struct
import zipfile
from pathlib import Path
from xml.etree import ElementTree

from docx import Document
from docx.enum.section import WD_ORIENT, WD_SECTION
from docx.enum.table import WD_CELL_VERTICAL_ALIGNMENT
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_TAB_ALIGNMENT, WD_TAB_LEADER
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Inches, Mm, Pt, RGBColor


ROOT = Path(__file__).resolve().parent
OUTPUT = ROOT / "corrected-chapters-1-5.docx"
SANITIZED_OUTPUT = ROOT / "corrected-chapters-1-5.sanitized.docx"
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
PORTRAIT_TABLE_WIDTH_DXA = 8_906
LANDSCAPE_TABLE_WIDTH_DXA = 13_838

CONTENTS_PAGE_NUMBERS = {
    "Preliminary Pages": "i",
    "Title Page": "i",
    "Declaration": "ii",
    "Certification": "iii",
    "Dedication": "iv",
    "Acknowledgments": "v",
    "Abstract": "vi",
    "Table of Contents": "viii",
    "List of Tables": "x",
    "List of Figures": "xi",
    "List of Listings": "xiii",
    "List of Abbreviations": "xiv",
    "Chapter One: Introduction": "1",
    "1.1 Background to the Study": "1",
    "1.2 Statement of the Problem": "2",
    "1.3 Aim of the Study": "4",
    "1.4 Objectives of the Study": "4",
    "1.5 Research Questions": "5",
    "1.7 Significance of the Study": "5",
    "1.8 Scope of the Study": "6",
    "1.9 Limitations of the Study": "7",
    "1.10 Definition of Terms": "7",
    "1.11 Organisation of the Report": "8",
    "Chapter Two: Literature Review": "9",
    "2.1 Introduction to the Chapter": "9",
    "2.2 Conceptual Review": "9",
    "2.3 Theoretical Framework": "13",
    "2.4 Empirical Review of Related Works": "14",
    "2.5 Review of Existing Systems/Tools": "16",
    "2.6 Comparative Analysis of Related Works": "17",
    "2.7 Identified Research Gap": "18",
    "2.8 Summary of the Chapter": "19",
    "Chapter Three: Methodology": "20",
    "3.1 Introduction to the Chapter": "20",
    "3.2 Research Design / Project Approach": "20",
    "3.3 Analysis of Existing System": "21",
    "3.4 Proposed System Overview": "22",
    "3.5 System Requirements": "24",
    "3.6 Data Collection Methods": "28",
    "3.7 Population and Sampling": "28",
    "3.8 System Architecture / Design": "29",
    "3.9 Use Case / UML Diagrams": "30",
    "3.10 Database Design": "33",
    "3.11 Algorithm / Model Design": "36",
    "3.12 Tools and Technologies": "37",
    "3.13 Ethical Considerations": "39",
    "3.14 Summary of the Chapter": "40",
    "Chapter Four: System Implementation and Testing": "41",
    "4.1 Implementation of the System Design": "41",
    "4.2 Module Integration and Coding": "46",
    "4.3 Testing Strategy and Procedures": "73",
    "4.4 Test Results and Discussion": "83",
    "Chapter Five: Summary, Conclusion and Recommendations": "90",
    "5.1 Introduction": "90",
    "5.2 Summary of Findings": "90",
    "5.3 Achievement of Objectives": "92",
    "5.4 Contributions of the Study": "94",
    "5.5 Conclusion": "95",
    "5.6 Limitations": "95",
    "5.7 Recommendations": "96",
    "5.8 Future Work": "97",
    "5.9 AI-Assistance Disclosure": "98",
    "References": "99",
}

LISTING_PAGE_NUMBERS = {
    "4.1": "47",
    "4.2": "47",
    "4.3": "48",
    "4.4": "49",
    "4.5": "52",
    "4.6": "53",
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


def set_run_font(
    run,
    size: int = 12,
    bold: bool = False,
    italic: bool = False,
    font_name: str = "Times New Roman",
) -> None:
    run.font.name = font_name
    run.font.size = Pt(size)
    run.font.bold = bold
    run.font.italic = italic
    run.font.color.rgb = RGBColor(0, 0, 0)
    r_fonts = run._element.get_or_add_rPr().rFonts
    r_fonts.set(qn("w:ascii"), font_name)
    r_fonts.set(qn("w:hAnsi"), font_name)
    r_fonts.set(qn("w:cs"), font_name)


def set_paragraph_base(paragraph, alignment=WD_ALIGN_PARAGRAPH.JUSTIFY) -> None:
    paragraph.alignment = alignment
    fmt = paragraph.paragraph_format
    fmt.line_spacing = 1.5
    fmt.space_after = Pt(8)
    fmt.space_before = Pt(0)


def configure_section(section, *, landscape: bool = False) -> None:
    section.orientation = WD_ORIENT.LANDSCAPE if landscape else WD_ORIENT.PORTRAIT
    section.page_width = Mm(297 if landscape else 210)
    section.page_height = Mm(210 if landscape else 297)
    section.top_margin = Inches(1)
    section.bottom_margin = Inches(1)
    section.left_margin = Inches(1)
    section.right_margin = Inches(1)


def add_page_number_field(paragraph) -> None:
    paragraph.alignment = WD_ALIGN_PARAGRAPH.CENTER
    run = paragraph.add_run()
    set_run_font(run)

    begin = OxmlElement("w:fldChar")
    begin.set(qn("w:fldCharType"), "begin")
    instruction = OxmlElement("w:instrText")
    instruction.set(qn("xml:space"), "preserve")
    instruction.text = " PAGE "
    separate = OxmlElement("w:fldChar")
    separate.set(qn("w:fldCharType"), "separate")
    value = OxmlElement("w:t")
    value.text = "1"
    end = OxmlElement("w:fldChar")
    end.set(qn("w:fldCharType"), "end")

    for element in [begin, instruction, separate, value, end]:
        run._r.append(element)


def set_page_number_format(section, number_format: str, *, start: int | None = None) -> None:
    section_properties = section._sectPr
    page_number_type = section_properties.find(qn("w:pgNumType"))
    if page_number_type is None:
        page_number_type = OxmlElement("w:pgNumType")
        section_properties.append(page_number_type)

    page_number_type.set(qn("w:fmt"), number_format)
    if start is None:
        page_number_type.attrib.pop(qn("w:start"), None)
    else:
        page_number_type.set(qn("w:start"), str(start))


def configure_page_numbering(document) -> None:
    for index, section in enumerate(document.sections):
        section.footer.is_linked_to_previous = False
        footer_paragraph = section.footer.paragraphs[0]
        footer_paragraph.clear()
        add_page_number_field(footer_paragraph)

        if index == 0:
            section.different_first_page_header_footer = True
            set_page_number_format(section, "lowerRoman", start=1)
        else:
            set_page_number_format(section, "decimal", start=1 if index == 1 else None)


def set_paragraph_border(paragraph) -> None:
    p_pr = paragraph._p.get_or_add_pPr()
    borders = p_pr.find(qn("w:pBdr"))
    if borders is None:
        borders = OxmlElement("w:pBdr")
        p_pr.append(borders)

    for edge in ["top", "left", "bottom", "right"]:
        element = OxmlElement(f"w:{edge}")
        element.set(qn("w:val"), "single")
        element.set(qn("w:sz"), "8")
        element.set(qn("w:space"), "6")
        element.set(qn("w:color"), "000000")
        borders.append(element)


def set_repeat_table_header(row) -> None:
    tr_pr = row._tr.get_or_add_trPr()
    header = OxmlElement("w:tblHeader")
    header.set(qn("w:val"), "true")
    tr_pr.append(header)


def keep_table_row_together(row) -> None:
    tr_pr = row._tr.get_or_add_trPr()
    cannot_split = OxmlElement("w:cantSplit")
    cannot_split.set(qn("w:val"), "true")
    tr_pr.append(cannot_split)


def table_column_widths(rows: list[list[str]], total_width: int) -> list[int]:
    column_count = len(rows[0])
    minimum = 850 if column_count >= 7 else 700
    available = total_width - (minimum * column_count)
    weights = []

    for column in range(column_count):
        longest = max(len(row[column]) if column < len(row) else 0 for row in rows)
        weights.append(max(6, min(longest, 48)))

    weight_total = sum(weights)
    widths = [minimum + int(available * weight / weight_total) for weight in weights]
    widths[-1] += total_width - sum(widths)

    return widths


def set_fixed_table_geometry(table, widths: list[int], total_width: int) -> None:
    table.autofit = False
    tbl_pr = table._tbl.tblPr

    table_width = tbl_pr.first_child_found_in("w:tblW")
    if table_width is None:
        table_width = OxmlElement("w:tblW")
        tbl_pr.append(table_width)
    table_width.set(qn("w:w"), str(total_width))
    table_width.set(qn("w:type"), "dxa")

    table_indent = tbl_pr.first_child_found_in("w:tblInd")
    if table_indent is None:
        table_indent = OxmlElement("w:tblInd")
        tbl_pr.append(table_indent)
    table_indent.set(qn("w:w"), "120")
    table_indent.set(qn("w:type"), "dxa")

    layout = tbl_pr.first_child_found_in("w:tblLayout")
    if layout is None:
        layout = OxmlElement("w:tblLayout")
        tbl_pr.append(layout)
    layout.set(qn("w:type"), "fixed")

    grid = table._tbl.tblGrid
    for child in list(grid):
        grid.remove(child)
    for width in widths:
        column = OxmlElement("w:gridCol")
        column.set(qn("w:w"), str(width))
        grid.append(column)

    for row in table.rows:
        for index, cell in enumerate(row.cells):
            tc_pr = cell._tc.get_or_add_tcPr()
            cell_width = tc_pr.first_child_found_in("w:tcW")
            if cell_width is None:
                cell_width = OxmlElement("w:tcW")
                tc_pr.append(cell_width)
            cell_width.set(qn("w:w"), str(widths[index]))
            cell_width.set(qn("w:type"), "dxa")


def set_cell_margins(cell, top=80, start=120, bottom=80, end=120) -> None:
    tc = cell._tc
    tc_pr = tc.get_or_add_tcPr()
    tc_mar = tc_pr.first_child_found_in("w:tcMar")
    if tc_mar is None:
        tc_mar = OxmlElement("w:tcMar")
        tc_pr.append(tc_mar)
    for margin, value in {
        "top": top,
        "start": start,
        "bottom": bottom,
        "end": end,
    }.items():
        node = tc_mar.find(qn(f"w:{margin}"))
        if node is None:
            node = OxmlElement(f"w:{margin}")
            tc_mar.append(node)
        node.set(qn("w:w"), str(value))
        node.set(qn("w:type"), "dxa")


def set_table_borders(table) -> None:
    tbl_pr = table._tbl.tblPr
    borders = tbl_pr.first_child_found_in("w:tblBorders")
    if borders is None:
        borders = OxmlElement("w:tblBorders")
        tbl_pr.append(borders)
    for edge in ["top", "left", "bottom", "right", "insideH", "insideV"]:
        tag = f"w:{edge}"
        element = borders.find(qn(tag))
        if element is None:
            element = OxmlElement(tag)
            borders.append(element)
        element.set(qn("w:val"), "single")
        element.set(qn("w:sz"), "6")
        element.set(qn("w:space"), "0")
        element.set(qn("w:color"), "000000")


def add_styled_paragraph(
    document,
    text: str,
    *,
    bold=False,
    italic=False,
    size=12,
    alignment=WD_ALIGN_PARAGRAPH.JUSTIFY,
    keep_with_next: bool = False,
    keep_together: bool = False,
):
    paragraph = document.add_paragraph()
    set_paragraph_base(paragraph, alignment)
    paragraph.paragraph_format.keep_with_next = keep_with_next
    paragraph.paragraph_format.keep_together = keep_together
    run = paragraph.add_run(text)
    set_run_font(run, size=size, bold=bold, italic=italic)
    return paragraph


def add_heading(document, text: str, level: int):
    style = f"Heading {level}"
    paragraph = document.add_paragraph(style=style)
    paragraph.alignment = WD_ALIGN_PARAGRAPH.CENTER if level in {1, 2} else WD_ALIGN_PARAGRAPH.JUSTIFY
    fmt = paragraph.paragraph_format
    fmt.line_spacing = 1.5
    fmt.space_before = Pt(12 if level >= 3 else 18)
    fmt.space_after = Pt(6)
    run = paragraph.add_run(text)
    set_run_font(run, size={1: 16, 2: 14, 3: 12, 4: 12}.get(level, 12), bold=True)

    return paragraph


def parse_inline_markdown(text: str) -> str:
    text = html.unescape(text)
    text = re.sub(r"\[([^\]]+)\]\([^)]+\)", r"\1", text)
    text = re.sub(r"</?[^>]+>", "", text)
    text = text.replace(r"\_", "_")
    text = text.replace(r"\[", "[").replace(r"\]", "]")
    text = text.replace("**", "")
    text = text.replace("*", "")
    text = text.replace("`", "")
    return text


def parse_table(lines: list[str]) -> list[list[str]]:
    rows = []
    for line in lines:
        cells = [parse_inline_markdown(cell.strip()) for cell in line.strip().strip("|").split("|")]
        rows.append(cells)
    return rows


def add_table(
    document,
    rows: list[list[str]],
    caption: str | None = None,
    *,
    landscape_already_started: bool = False,
    keep_landscape_after: bool = False,
) -> bool:
    if not rows:
        return landscape_already_started

    is_landscape = len(rows[0]) >= 7
    if is_landscape and not landscape_already_started:
        configure_section(document.add_section(WD_SECTION.NEW_PAGE), landscape=True)

    if caption is not None:
        add_styled_paragraph(
            document,
            caption,
            bold=True,
            alignment=WD_ALIGN_PARAGRAPH.CENTER,
            keep_with_next=True,
        )

    table = document.add_table(rows=len(rows), cols=len(rows[0]))
    total_width = LANDSCAPE_TABLE_WIDTH_DXA if is_landscape else PORTRAIT_TABLE_WIDTH_DXA
    set_fixed_table_geometry(table, table_column_widths(rows, total_width), total_width)
    set_table_borders(table)
    set_repeat_table_header(table.rows[0])
    for r_index, row in enumerate(rows):
        keep_table_row_together(table.rows[r_index])
        for c_index, value in enumerate(row):
            cell = table.cell(r_index, c_index)
            cell.vertical_alignment = WD_CELL_VERTICAL_ALIGNMENT.CENTER
            set_cell_margins(cell)
            paragraph = cell.paragraphs[0]
            paragraph.alignment = WD_ALIGN_PARAGRAPH.LEFT
            paragraph.paragraph_format.line_spacing = 1.15
            paragraph.paragraph_format.space_after = Pt(0)
            run = paragraph.add_run(value)
            set_run_font(run, size=9 if len(rows[0]) >= 7 else (10 if len(rows[0]) > 3 else 11), bold=r_index == 0)
    document.add_paragraph()

    if is_landscape and not keep_landscape_after:
        configure_section(document.add_section(WD_SECTION.NEW_PAGE))

    return is_landscape and keep_landscape_after


def add_code_listing(document, code: str) -> None:
    paragraph = document.add_paragraph()
    paragraph.alignment = WD_ALIGN_PARAGRAPH.LEFT
    paragraph.paragraph_format.line_spacing = 1.0
    paragraph.paragraph_format.space_before = Pt(3)
    paragraph.paragraph_format.space_after = Pt(10)
    paragraph.paragraph_format.keep_together = True
    set_paragraph_border(paragraph)
    run = paragraph.add_run(code)
    set_run_font(run, size=10, font_name="Courier New")


def add_image(document, alt_text: str, image_path: Path) -> None:
    add_styled_paragraph(
        document,
        alt_text,
        bold=True,
        alignment=WD_ALIGN_PARAGRAPH.CENTER,
        keep_with_next=True,
    )
    if image_path.exists():
        paragraph = document.add_paragraph()
        paragraph.alignment = WD_ALIGN_PARAGRAPH.CENTER
        paragraph.paragraph_format.keep_together = True
        run = paragraph.add_run()
        width_inches = 6.3
        if image_path.suffix.lower() == ".png":
            with image_path.open("rb") as image_file:
                header = image_file.read(24)
            if header[:8] == b"\x89PNG\r\n\x1a\n":
                pixel_width, pixel_height = struct.unpack(">II", header[16:24])
                width_inches = min(width_inches, 8.1 * pixel_width / pixel_height)
        run.add_picture(str(image_path), width=Inches(width_inches))
        document.add_paragraph()
    else:
        add_styled_paragraph(document, f"[Image missing: {image_path.name}]", italic=True, alignment=WD_ALIGN_PARAGRAPH.CENTER)


def add_markdown_file(document, file_name: str, *, start_on_new_page: bool = False) -> None:
    lines = (ROOT / file_name).read_text().splitlines()
    pending_table: list[str] = []
    pending_table_caption: str | None = None
    code_lines: list[str] = []
    in_code_block = False
    root_heading_seen = False
    landscape_section_open = False

    def flush_table() -> None:
        nonlocal pending_table, pending_table_caption, landscape_section_open
        if pending_table:
            rows = parse_table([line for i, line in enumerate(pending_table) if i != 1])
            keep_landscape_after = pending_table_caption == "Table 4.5: Structured System Test Cases and Current Results"
            landscape_section_open = add_table(
                document,
                rows,
                pending_table_caption,
                landscape_already_started=landscape_section_open,
                keep_landscape_after=keep_landscape_after,
            )
            pending_table = []
            pending_table_caption = None

    for raw_line in lines:
        line = raw_line.rstrip()
        stripped = line.strip()

        if stripped.startswith("```"):
            flush_table()
            if in_code_block:
                add_code_listing(document, "\n".join(code_lines))
                code_lines = []
                in_code_block = False
            else:
                in_code_block = True
            continue

        if in_code_block:
            code_lines.append(line)
            continue

        if pending_table and line.startswith("|"):
            pending_table.append(line)
            continue
        flush_table()

        if not stripped or stripped == "---" or stripped.startswith("> **References:**"):
            continue
        if stripped.startswith("# "):
            heading = add_heading(document, stripped[2:].strip(), 1)
            if start_on_new_page and not root_heading_seen:
                heading.paragraph_format.page_break_before = True
            root_heading_seen = True
            continue
        if stripped.startswith("## "):
            add_heading(document, stripped[3:].strip(), 2)
            continue
        if stripped.startswith("### "):
            add_heading(document, stripped[4:].strip(), 3)
            continue
        if stripped.startswith("#### "):
            add_heading(document, stripped[5:].strip(), 4)
            continue
        if stripped.startswith("|"):
            pending_table.append(line)
            continue
        image_match = re.match(r"!\[(.+?)\]\((.+?)\)", stripped)
        if image_match:
            alt, rel_path = image_match.groups()
            add_image(document, alt, ROOT / rel_path)
            continue
        table_caption = re.match(r"\*Table ([^*]+)\*", stripped)
        if table_caption:
            pending_table_caption = stripped.strip("*")
            continue
        listing_caption = re.match(r"\*Listing ([^*]+)\*", stripped)
        if listing_caption:
            add_styled_paragraph(
                document,
                stripped.strip("*"),
                bold=True,
                alignment=WD_ALIGN_PARAGRAPH.CENTER,
                keep_with_next=True,
            )
            continue
        numbered = re.match(r"^(\d+)\.\s+(.+)$", stripped)
        if numbered:
            add_styled_paragraph(document, f"{numbered.group(1)}. {parse_inline_markdown(numbered.group(2))}", alignment=WD_ALIGN_PARAGRAPH.JUSTIFY)
            continue
        add_styled_paragraph(document, parse_inline_markdown(stripped))
    flush_table()

    if code_lines:
        add_code_listing(document, "\n".join(code_lines))


def add_title_page(document) -> None:
    for index, line in enumerate(TITLE_LINES):
        paragraph = document.add_paragraph()
        set_paragraph_base(paragraph, WD_ALIGN_PARAGRAPH.CENTER)
        paragraph.paragraph_format.space_after = Pt(18 if index in {2, 3, 6, 7, 8} else 10)
        run = paragraph.add_run(line)
        set_run_font(run, size=12, bold=True)
    document.add_page_break()


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


def add_bullet_paragraph(document, text: str) -> None:
    paragraph = document.add_paragraph(style="List Bullet")
    set_paragraph_base(paragraph, WD_ALIGN_PARAGRAPH.JUSTIFY)
    paragraph.paragraph_format.left_indent = Inches(0.3)
    paragraph.paragraph_format.first_line_indent = Inches(-0.18)
    run = paragraph.add_run(parse_inline_markdown(text))
    set_run_font(run)


def add_certification(document, lines: list[str]) -> None:
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
    if narrative:
        add_styled_paragraph(document, narrative)

    rows = [
        ["Dr. Ayodeji Samuel Makinde", "______________", "______________"],
        ["Supervisor", "Signature", "Date"],
        ["______________", "______________", "______________"],
        ["Head of Department", "Signature", "Date"],
        ["______________", "______________", "______________"],
        ["External Examiner", "Signature", "Date"],
    ]
    table = document.add_table(rows=len(rows), cols=3)
    set_fixed_table_geometry(table, [4_200, 2_353, 2_353], PORTRAIT_TABLE_WIDTH_DXA)
    for row_index, row in enumerate(rows):
        for column_index, value in enumerate(row):
            cell = table.cell(row_index, column_index)
            cell.vertical_alignment = WD_CELL_VERTICAL_ALIGNMENT.CENTER
            set_cell_margins(cell, top=120, bottom=120)
            paragraph = cell.paragraphs[0]
            paragraph.alignment = WD_ALIGN_PARAGRAPH.CENTER
            paragraph.paragraph_format.line_spacing = 1.15
            paragraph.paragraph_format.space_after = Pt(0)
            run = paragraph.add_run(value)
            set_run_font(run, size=11, bold=row_index in {0, 2, 4})


def add_contents(document, lines: list[str]) -> None:
    for raw_line in lines:
        stripped = raw_line.strip()
        match = re.match(r"^(\s*)-\s+(.+)$", raw_line)
        if not stripped or stripped == "---" or match is None:
            continue

        depth = min(3, len(match.group(1)) // 2)
        text = parse_inline_markdown(match.group(2))
        paragraph = add_styled_paragraph(
            document,
            "",
            bold=depth == 0,
            alignment=WD_ALIGN_PARAGRAPH.LEFT,
        )
        paragraph.paragraph_format.left_indent = Inches(0.28 * depth)
        paragraph.paragraph_format.space_after = Pt(0 if depth else 3)
        paragraph.paragraph_format.tab_stops.add_tab_stop(
            Inches(6.15 - (0.28 * depth)),
            WD_TAB_ALIGNMENT.RIGHT,
            WD_TAB_LEADER.DOTS,
        )
        title_run = paragraph.add_run(text)
        set_run_font(title_run, bold=depth == 0)
        page_run = paragraph.add_run(f"\t{CONTENTS_PAGE_NUMBERS[text]}")
        set_run_font(page_run, bold=depth == 0)


def add_preliminary_body(document, lines: list[str]) -> None:
    pending_table: list[str] = []

    def flush_table() -> None:
        nonlocal pending_table
        if pending_table:
            add_table(document, parse_table([line for index, line in enumerate(pending_table) if index != 1]))
            pending_table = []

    for raw_line in lines:
        stripped = raw_line.strip()

        if pending_table and stripped.startswith("|"):
            pending_table.append(stripped)
            continue
        flush_table()

        if not stripped or stripped == "---" or stripped in {"<br/>", "<div align=\"center\">", "</div>"}:
            continue
        if stripped.startswith("|"):
            pending_table.append(stripped)
            continue

        bullet = re.match(r"^-\s+(.+)$", stripped)
        if bullet:
            add_bullet_paragraph(document, bullet.group(1))
            continue

        italic = stripped.startswith("*") and stripped.endswith("*")
        add_styled_paragraph(
            document,
            parse_inline_markdown(stripped),
            bold=stripped.startswith("**Keywords:**"),
            italic=italic,
        )

    flush_table()


def add_preliminary_pages(document) -> None:
    sections = preliminary_sections()

    for index, title in enumerate(PRELIMINARY_SECTIONS):
        heading = add_heading(document, title.upper(), 1)
        if index > 0:
            heading.paragraph_format.page_break_before = True
        lines = sections[title]

        if title == "Certification":
            add_certification(document, lines)
        elif title == "Table of Contents":
            add_contents(document, lines)
        elif title == "List of Listings":
            add_table(document, listing_rows())
            notes = [line for line in lines if line.strip().startswith("*") and not line.strip().startswith("| ")]
            for note in notes:
                add_styled_paragraph(document, parse_inline_markdown(note.strip()), italic=True)
        else:
            add_preliminary_body(document, lines)

def listing_rows() -> list[list[str]]:
    rows = [["Listing No.", "Title", "Page"]]
    pattern = re.compile(r"\*Listing (\d+\.\d+):\s*([^*]+)\*")

    for chapter in CHAPTERS:
        for line in (ROOT / chapter).read_text().splitlines():
            match = pattern.fullmatch(line.strip())
            if match:
                listing_number = match.group(1)
                rows.append(
                    [
                        listing_number,
                        match.group(2),
                        LISTING_PAGE_NUMBERS[listing_number],
                    ],
                )

    return rows


def configure_styles(document) -> None:
    styles = document.styles
    for style_name in ["Normal", "Heading 1", "Heading 2", "Heading 3", "Heading 4"]:
        style = styles[style_name]
        style.font.name = "Times New Roman"
        style.font.color.rgb = RGBColor(0, 0, 0)
        style._element.rPr.rFonts.set(qn("w:ascii"), "Times New Roman")
        style._element.rPr.rFonts.set(qn("w:hAnsi"), "Times New Roman")
    styles["Normal"].font.size = Pt(12)


def add_references(document) -> None:
    heading = add_heading(document, "REFERENCES", 1)
    heading.paragraph_format.page_break_before = True
    references = (ROOT / "references.md").read_text().splitlines()
    for line in references:
        stripped = line.strip()
        if not stripped or stripped.startswith("#") or stripped.startswith(">") or stripped == "---":
            continue
        paragraph = add_styled_paragraph(document, parse_inline_markdown(stripped), alignment=WD_ALIGN_PARAGRAPH.LEFT)
        paragraph.paragraph_format.left_indent = Inches(0.5)
        paragraph.paragraph_format.first_line_indent = Inches(-0.5)
        paragraph.paragraph_format.space_after = Pt(0)


def sanitize_title_borders(source: Path, destination: Path) -> None:
    namespace = {"w": "http://schemas.openxmlformats.org/wordprocessingml/2006/main"}
    temporary = destination.with_name(f".{destination.name}.tmp")
    temporary.unlink(missing_ok=True)

    with zipfile.ZipFile(source) as input_archive, zipfile.ZipFile(temporary, "w") as output_archive:
        for item in input_archive.infolist():
            data = input_archive.read(item.filename)

            if item.filename == "word/styles.xml":
                root = ElementTree.fromstring(data)
                for style in root.findall("w:style", namespace):
                    if style.get(qn("w:styleId")) != "Title":
                        continue
                    p_pr = style.find("w:pPr", namespace)
                    border = p_pr.find("w:pBdr", namespace) if p_pr is not None else None
                    if p_pr is not None and border is not None:
                        p_pr.remove(border)
                data = ElementTree.tostring(root, encoding="utf-8", xml_declaration=True)

            if item.filename == "word/document.xml":
                root = ElementTree.fromstring(data)
                body = root.find("w:body", namespace)
                if body is not None:
                    for paragraph in body.findall("w:p", namespace)[: len(TITLE_LINES)]:
                        p_pr = paragraph.find("w:pPr", namespace)
                        border = p_pr.find("w:pBdr", namespace) if p_pr is not None else None
                        if p_pr is not None and border is not None:
                            p_pr.remove(border)
                data = ElementTree.tostring(root, encoding="utf-8", xml_declaration=True)

            output_archive.writestr(item, data)

    temporary.replace(destination)


def main() -> None:
    document = Document()
    configure_section(document.sections[0])
    configure_styles(document)
    add_title_page(document)
    add_preliminary_pages(document)
    main_section = document.add_section(WD_SECTION.NEW_PAGE)
    configure_section(main_section)
    for index, chapter in enumerate(CHAPTERS):
        add_markdown_file(document, chapter, start_on_new_page=index > 0)
    add_references(document)
    configure_page_numbering(document)
    document.save(OUTPUT)
    sanitize_title_borders(OUTPUT, SANITIZED_OUTPUT)
    print(OUTPUT)
    print(SANITIZED_OUTPUT)


if __name__ == "__main__":
    main()
