#!/usr/bin/env python3
"""
CV Parser per Incide Engineering - Schema V2
Estrae automaticamente informazioni dai CV in formato PDF, DOCX, MSG

Changelog V2:
- Separazione experience (job history) da projects (referenze progettuali)
- Aggiunto professional_profile (breve summary)
- Warnings top-level per tracciare problemi di parsing
- Migliorata classificazione certificazioni (UNI, normative)
- Euristiche deterministiche per riconoscere progetti vs job
"""

import sys
import json
import re
from datetime import datetime
from pathlib import Path

# Schema version - PHP uses this to handle backward compatibility
SCHEMA_VERSION = 2

# ============================================================================
# TEXT EXTRACTION
# ============================================================================

def extract_text_from_pdf(pdf_path):
    """Estrae testo da PDF usando pypdf."""
    try:
        from pypdf import PdfReader
    except Exception:
        raise RuntimeError("Libreria pypdf non installata. Esegui: py -3 -m pip install pypdf")

    try:
        reader = PdfReader(pdf_path)
    except Exception as e:
        raise RuntimeError(f"Impossibile aprire PDF: {e}")

    text_parts = []
    for page in reader.pages:
        try:
            page_text = page.extract_text() or ""
        except Exception:
            page_text = ""
        if page_text:
            text_parts.append(page_text)

    return "\n".join(text_parts).strip()


def extract_text_from_docx(docx_path):
    """Estrae testo da file DOCX via XML parsing."""
    try:
        import zipfile
        import xml.etree.ElementTree as ET

        with zipfile.ZipFile(docx_path) as docx:
            xml_content = docx.read('word/document.xml')
            tree = ET.XML(xml_content)

            ns = {'w': 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'}
            paragraphs = tree.findall('.//w:p', ns)

            text_parts = []
            for paragraph in paragraphs:
                texts = paragraph.findall('.//w:t', ns)
                if texts:
                    paragraph_text = ''.join(node.text for node in texts if node.text)
                    text_parts.append(paragraph_text)

            return '\n'.join(text_parts)
    except Exception as e:
        return f"Errore estrazione DOCX: {str(e)}"


def extract_pdf_from_msg(msg_path):
    """Estrae PDF embedded da file MSG di Outlook."""
    try:
        with open(msg_path, 'rb') as f:
            data = f.read()

        pdf_start = data.find(b'%PDF')
        if pdf_start == -1:
            return None

        pdf_end = data.find(b'%%EOF', pdf_start)
        if pdf_end == -1:
            return None

        pdf_data = data[pdf_start:pdf_end + 6]

        temp_pdf = msg_path.replace('.msg', '_temp.pdf')
        with open(temp_pdf, 'wb') as f:
            f.write(pdf_data)

        return temp_pdf
    except Exception:
        return None


# ============================================================================
# PERSONAL INFO EXTRACTION
# ============================================================================

def extract_personal_info(text):
    """Estrae nome, email, telefono, indirizzo."""
    info = {
        'nome': None,
        'cognome': None,
        'email': None,
        'telefono': None,
        'indirizzo': None,
        'citta': None,
        'cap': None,
        'provincia': None
    }

    # Email
    email_pattern = r'\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b'
    emails = re.findall(email_pattern, text)
    if emails:
        info['email'] = emails[0]

    # Telefono italiano
    phone_pattern = r'(?:\+39\s*)?(?:\(?\d{2,4}\)?[\s.-]?)?\d{6,10}'
    phones = re.findall(phone_pattern, text)
    if phones:
        info['telefono'] = phones[0].strip()

    # CAP e provincia
    cap_pattern = r'\b(\d{5})\s*[,\s]*([A-Z]{2})\b'
    caps = re.findall(cap_pattern, text)
    if caps:
        info['cap'] = caps[0][0]
        info['provincia'] = caps[0][1]

    # Indirizzo - evita falsi positivi come "Corso BIM 200 ore"
    address_pattern = r'\b((?:Via|Viale|Piazza|Corso|V\.le|P\.zza)\s+[\w\s]{3,60}\s+\d{1,4}[\w\/\-]*)\b'
    addresses = re.findall(address_pattern, text, re.IGNORECASE)

    def looks_like_course(s):
        s_upper = s.upper()
        return ('CORSO' in s_upper and ('ORE' in s_upper or 'DURATA' in s_upper)) or ('BIM' in s_upper and 'CORSO' in s_upper)

    for a in addresses:
        a_clean = a.strip()
        if looks_like_course(a_clean):
            continue
        info['indirizzo'] = a_clean
        break

    # Nome e cognome (prime 25 righe, escludendo header/footer)
    lines = [l.strip() for l in text.split('\n') if l.strip()]
    candidates = lines[:25]

    def is_bad_name_line(s):
        s_upper = s.upper()
        if 'CURRICULUM' in s_upper or 'VITAE' in s_upper:
            return True
        if 'DOCX' in s_upper or 'PDF' in s_upper:
            return True
        if re.search(r'\b\d+\s*/\s*\d+\b', s):
            return True
        if '@' in s:
            return True
        if len(s) > 80:
            return True
        return False

    for line in candidates:
        if is_bad_name_line(line):
            continue

        clean = re.sub(r'[^\w\s]', ' ', line).strip()
        clean = re.sub(r'\s+', ' ', clean)
        parts = clean.split(' ')

        if len(parts) == 2:
            nome, cognome = parts[0], parts[1]
            if len(nome) >= 2 and len(cognome) >= 2:
                info['nome'] = nome.title()
                info['cognome'] = cognome.title()
                break

        if len(parts) == 3:
            nome = parts[0]
            cognome = ' '.join(parts[1:])
            if len(nome) >= 2 and len(cognome) >= 3:
                info['nome'] = nome.title()
                info['cognome'] = cognome.title()
                break

    # Citta (se cap trovato)
    if info['cap']:
        city_pattern = rf"{info['cap']}\s*[,\s]*([A-Z][a-zA-Z\s]+?)(?:\s*\(|$|\n)"
        cities = re.findall(city_pattern, text)
        if cities:
            info['citta'] = cities[0].strip()

    return info


# ============================================================================
# PROFESSIONAL PROFILE EXTRACTION
# ============================================================================

def extract_professional_profile(text):
    """
    Estrae un breve profilo professionale/summary dal CV.
    Cerca sezioni come "Profilo", "About", "Summary", "Presentazione".
    Max 600 caratteri.
    """
    profile_section = re.search(
        r'(?:PROFILO\s*(?:PROFESSIONALE)?|ABOUT\s*ME|SUMMARY|PRESENTAZIONE|OBIETTIVO\s*PROFESSIONALE|CHI\s*SONO)[\s:]*(.+?)(?:ESPERIENZA|ISTRUZIONE|FORMAZIONE|COMPETENZE|$)',
        text,
        re.IGNORECASE | re.DOTALL
    )

    if not profile_section:
        return None

    profile_text = profile_section.group(1).strip()

    # Pulisci e tronca
    profile_text = re.sub(r'\s+', ' ', profile_text).strip()

    if len(profile_text) < 30:
        return None

    if len(profile_text) > 600:
        profile_text = profile_text[:600].rsplit(' ', 1)[0] + '...'

    return profile_text


# ============================================================================
# EDUCATION EXTRACTION
# ============================================================================

def extract_education(text):
    """Estrae titoli di studio."""
    education = []

    edu_section = re.search(
        r'(?:ISTRUZIONE|FORMAZIONE|EDUCATION|TITOLI DI STUDIO)(.+?)(?:ESPERIENZA|COMPETENZE|CERTIFICAZIONI|REFERENZE|PROGETTI|$)',
        text,
        re.IGNORECASE | re.DOTALL
    )

    if not edu_section:
        return education

    edu_text = edu_section.group(1)

    degree_patterns = [
        (r'Laurea\s+(?:Magistrale|Specialistica)\s+in\s+([^,\n]+)', 'laurea_magistrale'),
        (r'Laurea\s+(?:Triennale)?\s+in\s+([^,\n]+)', 'laurea_triennale'),
        (r'(?:Diploma|Liceo|Istituto)\s+(?:di\s+)?([^,\n]+)', 'diploma'),
        (r'Master\s+(?:di\s+)?([^,\n]+)', 'master'),
        (r'Dottorato\s+(?:in\s+)?([^,\n]+)', 'dottorato'),
    ]

    for pattern, tipo in degree_patterns:
        matches = re.finditer(pattern, edu_text, re.IGNORECASE)
        for match in matches:
            titolo = match.group(1).strip()

            context = edu_text[max(0, match.start()-200):min(len(edu_text), match.end()+200)]
            istituto_match = re.search(r'(?:UNIVERSITÀ|Università|Istituto|I\.I\.S\.|Liceo)\s+([^,\n]{5,100})', context, re.IGNORECASE)
            istituto = istituto_match.group(0).strip() if istituto_match else None

            date_pattern = r'(\d{4})\s*[-–]\s*(\d{4}|in corso|presente)'
            date_match = re.search(date_pattern, context, re.IGNORECASE)
            data_inizio = date_match.group(1) if date_match else None
            data_fine = date_match.group(2) if date_match else None

            education.append({
                'tipo': tipo,
                'titolo': titolo,
                'istituto': istituto,
                'data_inizio': f"{data_inizio}-10-01" if data_inizio else None,
                'data_fine': f"{data_fine}-07-31" if data_fine and data_fine.isdigit() else None,
                'in_corso': 'corso' in (data_fine or '').lower() or 'presente' in (data_fine or '').lower()
            })

    return education


# ============================================================================
# PROJECT DETECTION - Heuristics for separating projects from job experience
# ============================================================================

# Keywords that strongly indicate PROJECT REFERENCES (vs job history)
# These are typical in engineering/architecture CVs (referenze progettuali)
PROJECT_KEYWORDS = [
    # Italian keywords
    r'\bREFERENZE\b', r'\bPROGETTI\b', r'\bINTERVENTO\b', r'\bINTERVENTI\b',
    r'\bIMPORTO\s*(?:LAVORI|OPERE|COMPLESSIVO)\b', r'\bDESTINAZIONE\s*D\'?USO\b',
    r'\bVINCOLI\b', r'\bCOMMITTENTE\b', r'\bCIG\b', r'\bCUP\b',
    r'\bS\.?L\.?P\.?\b', r'\bSUPERFICIE\s*(?:LORDA|UTILE)\b',
    r'\bOPERE\s*(?:PUBBLICHE|PRIVATE)\b', r'\bLAVORI\s*(?:PUBBLICI|PRIVATI)\b',
    r'\bPROGETTAZIONE\s*(?:DEFINITIVA|ESECUTIVA|PRELIMINARE)\b',
    r'\bDIREZIONE\s*LAVORI\b', r'\bD\.?L\.?\b',
    r'\bCOORDINAMENTO\s*(?:SICUREZZA|CSE|CSP)\b',
    r'\bAPPALTO\b', r'\bBANDO\b', r'\bGARA\b',
    r'\bRESTAURO\b', r'\bRISTRUTTURAZIONE\b', r'\bNUOVA\s*COSTRUZIONE\b',
    r'\bEDIFICIO\b', r'\bINFRASTRUTTURA\b',
    # English equivalents
    r'\bPROJECT\s*REFERENCES\b', r'\bPORTFOLIO\b', r'\bCONTRACT\s*VALUE\b'
]

# Keywords that indicate JOB HISTORY (employment, not projects)
JOB_KEYWORDS = [
    r'\bCONTRATTO\b', r'\bASSUNZIONE\b', r'\bDIPENDENTE\b',
    r'\bTEMPO\s*(?:DETERMINATO|INDETERMINATO)\b',
    r'\bSTAGE\b', r'\bTIROCINIO\b', r'\bAPPRENDISTATO\b',
    r'\bP\.?\s*IVA\b', r'\bFREELANCE\b', r'\bLIBERO\s*PROFESSIONISTA\b',
    r'\bCOLLABORAZIONE\b', r'\bCONSULENZA\b',
    r'\bS\.?R\.?L\.?\b', r'\bS\.?P\.?A\.?\b', r'\bS\.?N\.?C\.?\b',
    r'\bGMBH\b', r'\bLTD\b', r'\bLIMITED\b', r'\bINC\b', r'\bLLC\b',
    r'\bSTUDIO\b', r'\bSOCIETÀ\b', r'\bAZIENDA\b', r'\bIMPRESA\b'
]


def has_project_keywords(text_block):
    """Check if a text block contains project-related keywords."""
    text_upper = text_block.upper()
    score = 0
    for pattern in PROJECT_KEYWORDS:
        if re.search(pattern, text_upper, re.IGNORECASE):
            score += 1
    return score


def has_job_keywords(text_block):
    """Check if a text block contains job-related keywords."""
    text_upper = text_block.upper()
    score = 0
    for pattern in JOB_KEYWORDS:
        if re.search(pattern, text_upper, re.IGNORECASE):
            score += 1
    return score


def is_project_section(section_header, section_text):
    """
    Determine if a section is projects/references rather than job history.
    Uses header keywords + content analysis.
    """
    header_upper = section_header.upper() if section_header else ""

    # Strong header signals for projects
    project_headers = ['REFERENZ', 'PROGETT', 'PORTFOLIO', 'INTERVENTI', 'OPERE', 'LAVORI SVOLTI']
    if any(kw in header_upper for kw in project_headers):
        return True

    # Analyze content: count project vs job keywords
    proj_score = has_project_keywords(section_text)
    job_score = has_job_keywords(section_text)

    # If project keywords dominate, it's a project section
    return proj_score > job_score and proj_score >= 2


# ============================================================================
# PROJECT EXTRACTION (NEW in V2)
# ============================================================================

def extract_projects(text, warnings):
    """
    Estrae progetti/referenze progettuali.
    Tipico in CV di ingegneri/architetti con lista di progetti realizzati.

    Campi estratti (tutti opzionali):
    - nome: nome del progetto
    - luogo: localita
    - anno_inizio, anno_fine: timeline
    - ruolo: es. BIM Manager, Progettista
    - importo_euro: valore economico (se parsabile)
    - committente: cliente/ente
    - descrizione_breve: max 350 char
    - tags: array di keyword
    """
    projects = []

    # Cerca sezioni dedicate ai progetti/referenze
    project_section_patterns = [
        r'(?:REFERENZE\s*(?:PROGETTUALI)?|PROGETTI\s*(?:REALIZZATI|SIGNIFICATIVI)?|PORTFOLIO|INTERVENTI\s*(?:REALIZZATI)?|OPERE\s*(?:REALIZZATE)?|LAVORI\s*SVOLTI)[\s:]*(.+?)(?:ESPERIENZA\s*LAVORATIVA|ISTRUZIONE|FORMAZIONE|COMPETENZE|CERTIFICAZIONI|LINGUE|$)',
    ]

    project_text = None
    for pattern in project_section_patterns:
        match = re.search(pattern, text, re.IGNORECASE | re.DOTALL)
        if match:
            project_text = match.group(1)
            break

    if not project_text:
        # Nessuna sezione progetti esplicita - controlla se "ESPERIENZE" contiene in realtà progetti
        exp_section = re.search(
            r'(?:ESPERIENZA|ESPERIENZE\s*(?:LAVORATIVE|PROFESSIONALI)?|WORK\s*EXPERIENCE|CARRIERA)(.+?)(?:ISTRUZIONE|FORMAZIONE|CERTIFICAZIONI|COMPETENZE|LINGUE|$)',
            text,
            re.IGNORECASE | re.DOTALL
        )
        if exp_section:
            exp_text = exp_section.group(1)
            # Se la sezione "esperienze" ha molte keyword progetti, trattala come progetti
            if has_project_keywords(exp_text) >= 3 and has_project_keywords(exp_text) > has_job_keywords(exp_text):
                project_text = exp_text
                warnings.append("Sezione 'Esperienze' contiene referenze progettuali - classificata come progetti")

    if not project_text:
        return projects

    # Segmenta in blocchi (progetti individuali)
    # Usa separatori comuni: numeri, bullet points, righe vuote multiple
    blocks = segment_project_blocks(project_text)

    for block in blocks:
        if not block.strip():
            continue

        project = extract_single_project(block, warnings)
        if project:
            projects.append(project)

    return projects


def segment_project_blocks(text):
    """
    Divide il testo progetti in blocchi individuali.
    Usa pattern come numerazione, bullet points, o doppi a-capo.
    """
    lines = text.split('\n')
    blocks = []
    current_block = []
    empty_count = 0

    # Pattern per inizio nuovo progetto
    new_project_patterns = [
        r'^\s*\d+[\.\)]\s',      # 1. oppure 1)
        r'^\s*[•●○►▪]\s',        # bullet points
        r'^\s*[-–—]\s',          # trattini
        r'^PROGETTO\s*[:\d]',    # "PROGETTO 1:" o "PROGETTO:"
        r'^INTERVENTO\s*[:\d]',
        r'^OPERA\s*[:\d]',
    ]

    for line in lines:
        stripped = line.strip()

        if not stripped:
            empty_count += 1
            # Due righe vuote = fine blocco
            if empty_count >= 2 and current_block:
                blocks.append('\n'.join(current_block))
                current_block = []
            continue

        empty_count = 0

        # Check se inizia nuovo progetto
        is_new_project = any(re.match(p, stripped, re.IGNORECASE) for p in new_project_patterns)

        if is_new_project and current_block:
            blocks.append('\n'.join(current_block))
            current_block = []

        current_block.append(stripped)

    # Ultimo blocco
    if current_block:
        blocks.append('\n'.join(current_block))

    # Se nessun blocco trovato ma c'e testo, trattalo come singolo progetto
    if not blocks and text.strip():
        blocks = [text.strip()]

    return blocks


def extract_single_project(block_text, warnings):
    """
    Estrae dati da un singolo blocco progetto.
    Returns dict o None se blocco non valido.
    """
    if len(block_text.strip()) < 20:
        return None

    project = {
        'nome': None,
        'luogo': None,
        'anno_inizio': None,
        'anno_fine': None,
        'ruolo': None,
        'importo_euro': None,
        'committente': None,
        'descrizione_breve': None,
        'tags': []
    }

    lines = [l.strip() for l in block_text.split('\n') if l.strip()]
    block_upper = block_text.upper()

    # --- NOME PROGETTO ---
    # Prima riga significativa (non solo un numero o bullet)
    for line in lines[:3]:
        clean_line = re.sub(r'^\s*[\d\.\)\•\●\○\►\▪\-\–\—]+\s*', '', line).strip()
        if len(clean_line) >= 10 and len(clean_line) <= 150:
            project['nome'] = clean_line
            break

    # --- LUOGO ---
    # Pattern: comuni italiani, "presso", "a", "in"
    location_patterns = [
        r'(?:LUOGO|LOCALITA|COMUNE|CITTA|PRESSO|UBICAZIONE)[\s:]+([A-Za-z\s]+?)(?:\s*[\(\-,]|\s*$)',
        r'\b([A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)\s*\(([A-Z]{2})\)',  # "Milano (MI)"
        r'(?:A|IN|PRESSO)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)\b'
    ]
    for pattern in location_patterns:
        match = re.search(pattern, block_text, re.IGNORECASE)
        if match:
            loc = match.group(1).strip()
            if len(loc) >= 3 and len(loc) <= 50:
                project['luogo'] = loc
                break

    # --- ANNI (range o singolo) ---
    # Pattern: 2020-2022, 2020/2022, 2020 - presente
    year_patterns = [
        r'\b(20\d{2}|19\d{2})\s*[-–/]\s*(20\d{2}|19\d{2}|presente|in\s*corso|attuale)\b',
        r'\b(20\d{2}|19\d{2})\b'
    ]
    for pattern in year_patterns:
        match = re.search(pattern, block_text, re.IGNORECASE)
        if match:
            project['anno_inizio'] = int(match.group(1))
            if len(match.groups()) > 1 and match.group(2):
                end_str = match.group(2).lower()
                if end_str.isdigit():
                    project['anno_fine'] = int(end_str)
                elif '20' in end_str:
                    year_match = re.search(r'20\d{2}', end_str)
                    if year_match:
                        project['anno_fine'] = int(year_match.group())
                # "presente/in corso" -> None (ancora attivo)
            break

    # --- RUOLO ---
    role_patterns = [
        r'(?:RUOLO|INCARICO|MANSIONE|POSIZIONE)[\s:]+([^\n,]{5,60})',
        r'\b(BIM\s*(?:MANAGER|COORDINATOR|SPECIALIST|MODELER))\b',
        r'\b(PROJECT\s*MANAGER)\b',
        r'\b(PROGETTISTA(?:\s+\w+)?)\b',
        r'\b(DIRETTORE\s*(?:LAVORI|TECNICO|OPERATIVO))\b',
        r'\b(RESPONSABILE(?:\s+\w+)?)\b',
        r'\b(COORDINATORE(?:\s+\w+)?)\b'
    ]
    for pattern in role_patterns:
        match = re.search(pattern, block_text, re.IGNORECASE)
        if match:
            project['ruolo'] = match.group(1).strip()
            break

    # --- IMPORTO ---
    # Pattern: € 1.500.000, 1.5M, importo: 2000000
    importo_patterns = [
        r'(?:IMPORTO|VALORE|COSTO)[\s:]*(?:€|\bEUR\b)?\s*([\d.,]+)\s*(?:€|\bEUR\b|MILIONI|MLN|M)?',
        r'€\s*([\d.,]+)',
        r'([\d.,]+)\s*€'
    ]
    for pattern in importo_patterns:
        match = re.search(pattern, block_text, re.IGNORECASE)
        if match:
            value_str = match.group(1).replace('.', '').replace(',', '.')
            try:
                value = float(value_str)
                # Converti milioni se specificato
                if 'MILIONI' in block_upper or 'MLN' in block_upper or 'M€' in block_upper:
                    if value < 1000:  # probabilmente gia in milioni
                        value = value * 1_000_000
                # Se valore troppo piccolo, probabilmente in milioni
                if value < 10000 and value >= 1:
                    value = value * 1_000_000
                project['importo_euro'] = int(value)
            except ValueError:
                pass
            break

    # --- COMMITTENTE ---
    committente_patterns = [
        r'(?:COMMITTENTE|CLIENTE|STAZIONE\s*APPALTANTE|ENTE)[\s:]+([^\n]{5,100})',
        r'(?:PER\s+CONTO\s+DI|PER)[\s:]+([^\n]{5,80})'
    ]
    for pattern in committente_patterns:
        match = re.search(pattern, block_text, re.IGNORECASE)
        if match:
            project['committente'] = match.group(1).strip()[:100]
            break

    # --- TAGS ---
    tag_keywords = {
        'vincoli': [r'\bVINCOLI\b', r'\bVINCOLATO\b', r'\bTUTELATO\b'],
        'restauro': [r'\bRESTAURO\b', r'\bCONSERVATIVO\b'],
        'ristrutturazione': [r'\bRISTRUTTURAZIONE\b', r'\bRINNOVO\b'],
        'nuova_costruzione': [r'\bNUOVA\s*COSTRUZIONE\b', r'\bNUOVO\s*EDIFICIO\b'],
        'residenziale': [r'\bRESIDENZIALE\b', r'\bABITAZION\b'],
        'commerciale': [r'\bCOMMERCIALE\b', r'\bUFFICI\b'],
        'industriale': [r'\bINDUSTRIALE\b', r'\bCAPANNON\b'],
        'infrastruttura': [r'\bINFRASTRUTTUR\b', r'\bSTRADA\b', r'\bPONTE\b'],
        'aeroporto': [r'\bAEROPORTO\b', r'\bAEROPORTUALE\b'],
        'ospedale': [r'\bOSPEDALE\b', r'\bSANITARI\b'],
        'scuola': [r'\bSCUOLA\b', r'\bSCOLASTIC\b'],
        'pubblico': [r'\bPUBBLIC\b', r'\bCOMUNE\b', r'\bPROVINCIA\b', r'\bREGIONE\b'],
        'BIM': [r'\bBIM\b', r'\bREVIT\b'],
        'sicurezza': [r'\bCSE\b', r'\bCSP\b', r'\bSICUREZZA\b', r'\bD\.?LGS\.?\s*81\b'],
        'strutture': [r'\bSTRUTTUR\b', r'\bCALCOLI\b', r'\bSTATIC\b'],
        'impiantistica': [r'\bIMPIANT\b', r'\bMECCANIC\b', r'\bELETTRIC\b'],
        'urbanistica': [r'\bURBANISTIC\b', r'\bPRG\b', r'\bPGT\b']
    }

    for tag, patterns in tag_keywords.items():
        for pattern in patterns:
            if re.search(pattern, block_upper):
                project['tags'].append(tag)
                break

    # --- DESCRIZIONE BREVE ---
    # Usa testo rimanente, escluse righe gia parsate, max 350 char
    desc_lines = []
    for line in lines:
        # Salta righe che sembrano metadata
        if re.match(r'^(?:LUOGO|LOCALITA|IMPORTO|COMMITTENTE|RUOLO|INCARICO)[\s:]', line, re.IGNORECASE):
            continue
        if line == project['nome']:
            continue
        if len(line) > 10:
            desc_lines.append(line)

    if desc_lines:
        desc = ' '.join(desc_lines[:5])  # max 5 righe
        desc = re.sub(r'\s+', ' ', desc).strip()
        if len(desc) > 350:
            desc = desc[:350].rsplit(' ', 1)[0] + '...'
        project['descrizione_breve'] = desc

    # Valida: almeno un campo significativo riempito
    has_content = (
        project['nome'] or
        project['descrizione_breve'] or
        project['importo_euro'] or
        project['committente']
    )

    if not has_content:
        return None

    # Warning se campi incompleti
    if not project['nome'] and project['descrizione_breve']:
        warnings.append("Progetto senza nome rilevato - usata descrizione")

    return project


# ============================================================================
# EXPERIENCE EXTRACTION (JOB HISTORY ONLY)
# ============================================================================

def extract_experience(text, warnings):
    """
    Estrae SOLO esperienze lavorative (job history).
    Progetti/referenze vengono esclusi e gestiti da extract_projects().
    """
    experiences = []

    # Pattern per sezione esperienza
    exp_section = re.search(
        r'(?:ESPERIENZA|ESPERIENZE\s*(?:LAVORATIVE|PROFESSIONALI)?|WORK\s*EXPERIENCE|CARRIERA)(.+?)(?:ISTRUZIONE|FORMAZIONE|CERTIFICAZIONI|COMPETENZE|LINGUE|REFERENZE|PROGETTI|$)',
        text,
        re.IGNORECASE | re.DOTALL
    )

    if not exp_section:
        return experiences

    exp_text = exp_section.group(1)

    # Se la sezione e principalmente progetti, salta (verra gestita da extract_projects)
    if has_project_keywords(exp_text) >= 3 and has_project_keywords(exp_text) > has_job_keywords(exp_text):
        warnings.append("Sezione 'Esperienze' classificata come progetti - nessun job history estratto")
        return experiences

    lines = [l.strip() for l in exp_text.split('\n')]

    # Segmentazione a blocchi
    blocks = []
    current_block = []
    empty_line_count = 0

    section_keywords = r'(?:ISTRUZIONE|FORMAZIONE|EDUCATION|COMPETENZE|SKILLS|LINGUE|LANGUAGES|CERTIFICAZIONI|QUALIFICHE|PROFILO|ABOUT|REFERENZE|PROGETTI)'
    date_pattern = r'(\d{4}|\w+\s+\d{4}|\d{1,2}[\/\-]\d{4})\s*[-–]\s*(\d{4}|\w+\s+\d{4}|\d{1,2}[\/\-]\d{4}|in corso|presente|attuale|current)'

    for i, line in enumerate(lines):
        if not line:
            empty_line_count += 1
            if empty_line_count >= 2 and current_block:
                blocks.append(current_block)
                current_block = []
            continue

        empty_line_count = 0

        if re.match(section_keywords, line, re.IGNORECASE):
            if current_block:
                blocks.append(current_block)
                current_block = []
            break

        # Nuovo blocco se nuova data trovata
        if re.search(date_pattern, line, re.IGNORECASE):
            has_dates = any(re.search(date_pattern, l, re.IGNORECASE) for l in current_block)
            if has_dates and current_block:
                blocks.append(current_block)
                current_block = []

        current_block.append(line)

        if len(current_block) > 15:
            blocks.append(current_block)
            current_block = []

    if current_block:
        blocks.append(current_block)

    # Processa ogni blocco
    for block in blocks:
        if not block:
            continue

        block_text = '\n'.join(block)

        # SKIP blocco se ha troppe keyword progetti
        # Questo evita di confondere referenze progettuali con job
        proj_score = has_project_keywords(block_text)
        job_score = has_job_keywords(block_text)

        if proj_score >= 2 and proj_score > job_score:
            warnings.append(f"Blocco esperienza saltato - sembra un progetto: {block_text[:50]}...")
            continue

        exp = extract_single_experience(block, date_pattern, warnings)

        if exp and (exp['azienda'] or exp['posizione']):
            experiences.append(exp)

    return experiences


def extract_single_experience(block, date_pattern, warnings):
    """Estrae dati da un singolo blocco esperienza lavorativa."""
    exp = {
        'azienda': None,
        'posizione': None,
        'data_inizio': None,
        'data_fine': None,
        'in_corso': False,
        'descrizione': None
    }

    block_text = '\n'.join(block)

    # Estrai date
    date_match = re.search(date_pattern, block_text, re.IGNORECASE)
    if date_match:
        exp['data_inizio'] = date_match.group(1)
        exp['data_fine'] = date_match.group(2)
        exp['in_corso'] = any(x in date_match.group(2).lower() for x in ['corso', 'presente', 'attuale', 'current'])

    # Filtra righe con date
    content_lines = []
    for l in block:
        if not l:
            continue
        if re.search(date_pattern, l, re.IGNORECASE):
            continue
        content_lines.append(l)

    # Scoring per azienda vs ruolo
    line_scores = []
    for line in content_lines:
        company_score = 0
        role_score = 0
        line_upper = line.upper()

        # Segnali AZIENDA
        company_keywords = [
            ('S.R.L.', 15), ('S.P.A.', 15), ('SRL', 12), ('SPA', 12),
            ('S.N.C.', 12), ('S.A.S.', 12), ('SNC', 10), ('SAS', 10),
            ('GMBH', 12), ('LTD', 12), ('LIMITED', 12), ('INC', 12), ('LLC', 12),
            ('CORPORATION', 10), ('CORP', 10),
            ('COMPANY', 4), ('CO.', 4),
            ('STUDIO', 4), ('SOCIETÀ', 10), ('SOCIETA', 10),
            ('AZIENDA', 4), ('IMPRESA', 4), ('DITTA', 4),
            ('GROUP', 4), ('GRUPPO', 4)
        ]
        for keyword, weight in company_keywords:
            if re.search(r'^[A-Z0-9]+$', keyword):
                if re.search(rf'\b{keyword}\b', line_upper):
                    company_score += weight
            else:
                kw = re.escape(keyword).replace(r'\.', r'\.?').replace(r'\ ', r'\s*')
                if re.search(kw, line_upper):
                    company_score += weight

        if re.search(r'\bP\.?\s?IVA\b|\bVAT\b', line_upper):
            company_score += 20

        # Segnali RUOLO
        role_keywords = [
            ('PROJECT MANAGER', 18), ('PROGRAM MANAGER', 18), ('PRODUCT MANAGER', 18),
            ('INGEGNERE', 15), ('ENGINEER', 15), ('ARCHITETTO', 15), ('ARCHITECT', 15),
            ('GEOMETRA', 15), ('TECNICO', 12), ('TECHNICIAN', 12),
            ('RESPONSABILE', 12), ('MANAGER', 10), ('COORDINATOR', 12), ('COORDINATORE', 12),
            ('DIRETTORE', 12), ('DIRECTOR', 12), ('CAPO', 10), ('HEAD', 10), ('LEAD', 10),
            ('SENIOR', 8), ('JUNIOR', 8), ('SPECIALIST', 10), ('SPECIALISTA', 10),
            ('CONSULENTE', 10), ('CONSULTANT', 10), ('ANALISTA', 10), ('ANALYST', 10),
            ('DEVELOPER', 12), ('SVILUPPATORE', 12), ('DESIGNER', 10), ('DISEGNATORE', 10),
            ('PROGETTISTA', 12), ('BIM', 8), ('CAD', 6), ('ASSISTANT', 8), ('ASSISTENTE', 8)
        ]
        for keyword, weight in role_keywords:
            if re.search(r'^[A-Z0-9]+$', keyword):
                if re.search(rf'\b{keyword}\b', line_upper):
                    role_score += weight
            else:
                kw = re.escape(keyword).replace(r'\.', r'\.?').replace(r'\ ', r'\s*')
                if re.search(kw, line_upper):
                    role_score += weight

        # Verbi azione a inizio riga = probabilmente descrizione, non ruolo
        action_verbs = ['GESTIONE', 'COORDINAMENTO', 'SVILUPPO', 'PROGETTAZIONE', 'ANALISI',
                       'MANAGEMENT', 'COORDINATION', 'DEVELOPMENT', 'DESIGN', 'ANALYSIS']
        for verb in action_verbs:
            if line_upper.startswith(verb):
                role_score += 8

        # Penalita righe lunghe per ruolo
        if len(line) > 60:
            role_score = max(0, role_score - 5)

        if len(line) < 5:
            company_score = max(0, company_score - 10)

        line_scores.append({
            'line': line,
            'company_score': company_score,
            'role_score': role_score
        })

    # Seleziona azienda
    if line_scores:
        best_company = max(line_scores, key=lambda x: x['company_score'])
        if best_company['company_score'] >= 10:
            exp['azienda'] = best_company['line']
        elif len(content_lines) >= 1:
            exp['azienda'] = content_lines[0]

    # Seleziona posizione
    remaining_lines = [ls for ls in line_scores if ls['line'] != exp['azienda']]
    if remaining_lines:
        best_role = max(remaining_lines, key=lambda x: x['role_score'])
        if best_role['role_score'] >= 10:
            exp['posizione'] = best_role['line']
        elif len(content_lines) >= 2 and exp['azienda'] == content_lines[0]:
            exp['posizione'] = content_lines[1]
        elif len(content_lines) >= 1 and not exp['azienda']:
            exp['posizione'] = content_lines[0]

    # Descrizione
    if len(content_lines) > 2:
        descr = ' '.join(content_lines[2:])
        descr = re.sub(r'\s+', ' ', descr).strip()
        if len(descr) > 500:
            descr = descr[:500].rsplit(' ', 1)[0]
        exp['descrizione'] = descr if descr else None

    return exp


# ============================================================================
# SKILLS EXTRACTION
# ============================================================================

def extract_skills(text):
    """Estrae competenze tecniche e software."""
    skills = []

    software_engineering = [
        'AutoCAD', 'Revit', 'ArchiCAD', 'SketchUp', '3ds Max', 'Rhino',
        'SAP2000', 'Midas', 'Straus7', 'ETABS', 'Tekla',
        'Primus', 'STR Vision', 'CerTus', 'TeamSystem',
        'MS Project', 'Primavera', 'BIM 360', 'Navisworks',
        'QGIS', 'ArcGIS', 'Civil 3D',
        'Python', 'MATLAB', 'Excel', 'VBA',
        'Photoshop', 'Illustrator', 'InDesign'
    ]

    office_skills = [
        'Microsoft Office', 'Word', 'Excel', 'PowerPoint', 'Outlook',
        'Google Workspace', 'Google Docs', 'Google Sheets', 'Gmail'
    ]

    all_skills = software_engineering + office_skills

    text_upper = text.upper()

    for skill in all_skills:
        if skill.upper() in text_upper:
            if skill in software_engineering:
                categoria = 'software' if any(x in skill for x in ['CAD', 'Revit', 'BIM', 'SAP', 'Primus']) else 'tool'
            else:
                categoria = 'software'

            skills.append({
                'nome': skill,
                'categoria': categoria,
                'livello': 'intermedio'
            })

    return skills


# ============================================================================
# LANGUAGES EXTRACTION
# ============================================================================

def extract_languages(text):
    """Estrae competenze linguistiche."""
    languages = []

    lang_section = re.search(
        r'(?:LINGUE|COMPETENZE LINGUISTICHE|LANGUAGES)(.+?)(?:COMPETENZE|CERTIFICAZIONI|HOBBY|$)',
        text,
        re.IGNORECASE | re.DOTALL
    )

    if not lang_section:
        return languages

    lang_text = lang_section.group(1)

    common_languages = ['Italiano', 'Inglese', 'Francese', 'Tedesco', 'Spagnolo', 'Portoghese', 'Russo', 'Cinese', 'Giapponese', 'Arabo']

    for lang in common_languages:
        pattern = rf'{lang}\s*[:–-]?\s*([A-C][12]|madrelingua|mother tongue|native|fluente|ottimo|buono|scolastico)'
        match = re.search(pattern, lang_text, re.IGNORECASE)
        if match:
            level_text = match.group(1).upper()

            if 'MADRELINGUA' in level_text or 'NATIVE' in level_text or 'MOTHER' in level_text:
                level = 'madrelingua'
            elif level_text in ['A1', 'A2', 'B1', 'B2', 'C1', 'C2']:
                level = level_text
            elif 'FLUENTE' in level_text or 'OTTIMO' in level_text:
                level = 'C1'
            elif 'BUONO' in level_text:
                level = 'B2'
            else:
                level = 'B1'

            languages.append({
                'lingua': lang,
                'livello': level,
                'certificazione': None
            })

    return languages


# ============================================================================
# CERTIFICATIONS EXTRACTION (IMPROVED IN V2)
# ============================================================================

def extract_certifications(text, warnings):
    """
    Estrae certificazioni con tipizzazione migliorata.
    Distingue certificazioni normative (UNI, ISO) e professionali (BIM, PMP).
    """
    certifications = []

    cert_section = re.search(
        r'(?:CERTIFICAZIONI|CERTIFICATI|ATTESTATI|QUALIFICHE)(.+?)(?:COMPETENZE|LINGUE|HOBBY|REFERENZE|PROGETTI|$)',
        text,
        re.IGNORECASE | re.DOTALL
    )

    if not cert_section:
        return certifications

    cert_text = cert_section.group(1)

    raw_lines = [l.strip() for l in cert_text.split('\n') if l.strip()]
    lines = []
    for l in raw_lines:
        ln = len(l)
        if ln > 200:
            continue
        if ln >= 10:
            lines.append(l)
            continue
        upper_l = l.upper()
        if re.search(r'\b(ISO|UNI|EN|IEC|PMP|ECDL|EIPASS|BIM)\b', upper_l):
            lines.append(l)

    for line in lines:
        line_clean = line.strip()
        if not line_clean:
            continue

        upper = line_clean.upper()

        # Scarta titoli/sezioni
        if upper in ['ESPERIENZA PROFESSIONALE', 'ESPERIENZA', 'COMPETENZE', 'LINGUE',
                     'ISTRUZIONE', 'FORMAZIONE', 'CERTIFICAZIONI', 'ATTESTATI', 'QUALIFICHE']:
            continue

        generic_terms = ['ATTESTATO', 'CERTIFICATO', 'QUALIFICA', 'DIPLOMA', 'PATENTE']
        if upper in generic_terms:
            continue

        if re.match(r'^(Ho |Sono |Possiedo |Conseguito |Ottenuto )', line_clean, re.IGNORECASE):
            continue

        # --- CLASSIFICAZIONE TIPO (IMPROVED) ---
        cert_type = None
        is_normative = False

        # Certificazioni NORMATIVE (UNI, ISO, EN, IEC)
        normative_patterns = [
            r'\bUNI\s*[\d\-:]+',
            r'\bISO\s*[\d\-:]+',
            r'\bEN\s*[\d\-:]+',
            r'\bIEC\s*[\d\-:]+',
            r'\bD\.?\s*LGS\.?\s*\d+',
            r'\bDPR\s*\d+',
        ]
        for pattern in normative_patterns:
            if re.search(pattern, upper):
                cert_type = 'certificazione'
                is_normative = True
                break

        # Certificazioni BIM
        if not cert_type:
            bim_patterns = [
                r'\bBIM\s*(?:MANAGER|COORDINATOR|SPECIALIST|MODELER)\b',
                r'\bBUILDING\s*SMART\b',
                r'\bICMQ\b.*\bBIM\b',
            ]
            for pattern in bim_patterns:
                if re.search(pattern, upper):
                    cert_type = 'certificazione'
                    break

        # Keyword CERTIFICAZIONE generica
        if not cert_type:
            cert_keywords = [
                r'\bCERTIFICATO\b', r'\bCERTIFICAZIONE\b', r'\bATTESTATO PROFESSIONALE\b',
                r'\bCERTIFICATE\b', r'\bCERTIFICATION\b', r'\bACCREDITATION\b'
            ]
            for kw in cert_keywords:
                if re.search(kw, upper):
                    cert_type = 'certificazione'
                    break

        # Keyword ALBO
        if not cert_type:
            albo_keywords = [
                r'\bALBO\b', r'\bORDINE PROFESSIONALE\b', r'\bISCRIZIONE\b',
                r'\bPROFESSIONAL REGISTER\b', r'\bREGISTRATION\b'
            ]
            for kw in albo_keywords:
                if re.search(kw, upper):
                    cert_type = 'albo'
                    break

        # Keyword CORSO
        if not cert_type:
            corso_keywords = [
                r'\bCORSO\b', r'\bFORMAZIONE\b', r'\bTRAINING\b', r'\bWORKSHOP\b',
                r'\bSEMINARIO\b', r'\bMASTER\b', r'\bSPECIALIZZAZIONE\b'
            ]
            for kw in corso_keywords:
                if re.search(kw, upper):
                    cert_type = 'corso'
                    break

        if not cert_type:
            cert_type = 'certificazione'

        # Data rilascio
        data_rilascio = None
        year_match = re.search(r'\b(19\d{2}|20\d{2})\b', line_clean)
        if year_match:
            data_rilascio = f"{year_match.group(1)}-01-01"
        else:
            month_year_match = re.search(r'\b(0[1-9]|1[0-2])[\/\-](19\d{2}|20\d{2})\b', line_clean)
            if month_year_match:
                month = month_year_match.group(1)
                year = month_year_match.group(2)
                data_rilascio = f"{year}-{month}-01"

        certifications.append({
            'nome': line_clean[:200],
            'ente_rilascio': None,
            'data_rilascio': data_rilascio,
            'type': cert_type,
            'is_normative': is_normative  # Flag per scoring
        })

        if len(certifications) >= 15:
            break

    return certifications


# ============================================================================
# PROFESSION CLASSIFICATION
# ============================================================================

def classify_profession(text, education, experience, skills, projects):
    """Classifica la professionalita del candidato."""

    text_upper = text.upper()

    professions = {
        'Ingegnere Civile': ['INGEGNERE CIVILE', 'CIVIL ENGINEER', 'INGEGNERIA CIVILE'],
        'Ingegnere Strutturale': ['INGEGNERE STRUTTURALE', 'STRUCTURAL ENGINEER', 'CALCOLO STRUTTURALE', 'PROGETTISTA STRUTTURALE'],
        'Geometra': ['GEOMETRA', 'GEOMETER', 'TOPOGRAFIA', 'RILIEVO'],
        'Addetto Contabilità': ['CONTABIL', 'ACCOUNTING', 'AMMINISTRAZIONE', 'RAGIONERIA', 'BUSTE PAGA'],
        'Project Manager': ['PROJECT MANAGER', 'GESTIONE PROGETTI', 'PM'],
        'BIM Specialist': ['BIM', 'REVIT SPECIALIST', 'BIM MANAGER', 'BIM COORDINATOR'],
        'Disegnatore CAD': ['DISEGNATORE', 'CAD DESIGNER', 'AUTOCAD', 'DRAFTING']
    }

    scores = {}

    for profession, keywords in professions.items():
        score = 0
        for keyword in keywords:
            if keyword in text_upper:
                score += 10

        for edu in education:
            if edu.get('titolo'):
                for keyword in keywords:
                    if keyword in edu['titolo'].upper():
                        score += 15

        for exp in experience:
            if exp.get('posizione'):
                for keyword in keywords:
                    if keyword in exp['posizione'].upper():
                        score += 20

        # Bonus da progetti (V2)
        for proj in projects:
            if proj.get('ruolo'):
                for keyword in keywords:
                    if keyword in proj['ruolo'].upper():
                        score += 15

        skill_names = [s['nome'].upper() for s in skills]
        profession_skills = {
            'Ingegnere Civile': ['AUTOCAD', 'CIVIL 3D'],
            'Ingegnere Strutturale': ['SAP2000', 'MIDAS', 'STRAUS'],
            'BIM Specialist': ['REVIT', 'NAVISWORKS', 'BIM'],
            'Addetto Contabilità': ['TEAMSYSTEM', 'EXCEL'],
            'Disegnatore CAD': ['AUTOCAD', 'REVIT', 'SKETCHUP']
        }

        if profession in profession_skills:
            for skill_keyword in profession_skills[profession]:
                if any(skill_keyword in s for s in skill_names):
                    score += 5

        scores[profession] = score

    if scores:
        best_profession = max(scores, key=scores.get)
        if scores[best_profession] > 10:
            return best_profession

    return 'Non classificato'


# ============================================================================
# SCORING CALCULATION
# ============================================================================

def calculate_score(education, experience, skills, languages, certifications, profession, projects):
    """
    Calcola score complessivo del candidato.
    V2: Include progetti e certificazioni normative nel calcolo.
    """

    weights = {
        'istruzione': 0.25,
        'esperienza': 0.40,
        'competenze': 0.25,
        'lingue': 0.10
    }

    # Score istruzione (0-100)
    edu_score = 0
    for edu in education:
        tipo = edu.get('tipo', '')
        if tipo == 'dottorato':
            edu_score += 40
        elif tipo == 'laurea_magistrale':
            edu_score += 30
        elif tipo == 'master':
            edu_score += 25
        elif tipo == 'laurea_triennale':
            edu_score += 20
        elif tipo == 'diploma':
            edu_score += 10
    edu_score = min(100, edu_score)

    # Score esperienza (0-100)
    exp_score = 0
    total_months = 0
    for exp in experience:
        months = 12
        if exp.get('data_inizio') and exp.get('data_fine'):
            try:
                start_year = int(re.search(r'\d{4}', exp['data_inizio']).group())
                if exp.get('in_corso'):
                    end_year = datetime.now().year
                else:
                    end_year = int(re.search(r'\d{4}', exp['data_fine']).group())
                months = (end_year - start_year) * 12
            except:
                pass
        total_months += months

    exp_score = min(100, (total_months / 12) * 15)

    # Score competenze (0-100)
    skills_score = min(100, len(skills) * 8)

    # Score lingue (0-100)
    lang_score = 0
    for lang in languages:
        level = lang.get('livello', '')
        if level == 'madrelingua':
            lang_score += 15
        elif level in ['C2', 'C1']:
            lang_score += 25
        elif level in ['B2', 'B1']:
            lang_score += 15
        else:
            lang_score += 5
    lang_score = min(100, lang_score)

    # Score totale ponderato
    total_score = (
        edu_score * weights['istruzione'] +
        exp_score * weights['esperienza'] +
        skills_score * weights['competenze'] +
        lang_score * weights['lingue']
    )

    return round(total_score, 2)


# ============================================================================
# MAIN PARSER FUNCTION
# ============================================================================

def parse_cv(file_path):
    """Funzione principale per il parsing del CV."""

    file_path = Path(file_path)
    warnings = []  # Collect warnings during parsing

    if not file_path.exists():
        return {'error': f'File non trovato: {file_path}'}

    # Estrazione testo
    try:
        text = ""
        suffix = file_path.suffix.lower()

        if suffix == '.pdf':
            text = extract_text_from_pdf(str(file_path))
        elif suffix in ['.docx', '.doc']:
            text = extract_text_from_docx(str(file_path))
        elif suffix == '.msg':
            temp_pdf = extract_pdf_from_msg(str(file_path))
            if temp_pdf:
                try:
                    text = extract_text_from_pdf(temp_pdf)
                finally:
                    try:
                        Path(temp_pdf).unlink(missing_ok=True)
                    except Exception:
                        pass
            else:
                return {'error': 'Impossibile estrarre CV dal file MSG'}
        else:
            return {'error': f'Formato file non supportato: {suffix}'}

    except Exception as e:
        return {'error': f'Errore estrazione testo: {str(e)}'}

    if not text or len(text.strip()) < 200:
        return {'error': 'Impossibile estrarre testo significativo dal CV (PDF potrebbe essere scansione)'}

    # Parsing informazioni
    personal_info = extract_personal_info(text)
    professional_profile = extract_professional_profile(text)
    education = extract_education(text)

    # V2: Extract projects BEFORE experience to detect project-heavy sections
    projects = extract_projects(text, warnings)
    experience = extract_experience(text, warnings)

    skills = extract_skills(text)
    languages = extract_languages(text)
    certifications = extract_certifications(text, warnings)

    # Classificazione professionalita (include projects)
    profession = classify_profession(text, education, experience, skills, projects)

    # Calcolo score (include projects)
    score = calculate_score(education, experience, skills, languages, certifications, profession, projects)

    # Warnings per dati mancanti
    if not personal_info.get('nome') or not personal_info.get('cognome'):
        warnings.append("Nome o cognome non rilevato")

    if not education:
        warnings.append("Nessun titolo di studio estratto")

    if not experience and not projects:
        warnings.append("Nessuna esperienza lavorativa o progetto estratto")

    if projects and not experience:
        warnings.append("Solo progetti rilevati, nessun job history - verifica CV")

    # Risultato JSON V2
    result = {
        'schema_version': SCHEMA_VERSION,
        'file_originale': file_path.name,
        'personal_info': personal_info,
        'professional_profile': professional_profile,
        'education': education,
        'experience': experience,
        'projects': projects,  # NEW in V2
        'skills': skills,
        'languages': languages,
        'certifications': certifications,
        'profession': profession,
        'score': score,
        'warnings': warnings,  # NEW in V2: top-level warnings from parser
        'parsing_date': datetime.now().isoformat()
    }

    return result


# ============================================================================
# MAIN
# ============================================================================

if __name__ == '__main__':
    if len(sys.argv) < 2:
        print(json.dumps({'error': 'Specificare il percorso del file CV'}, ensure_ascii=False))
        sys.exit(1)

    cv_file = sys.argv[1]
    result = parse_cv(cv_file)

    # Output JSON con encoding UTF-8
    output_json = json.dumps(result, ensure_ascii=False, indent=2)

    if hasattr(sys.stdout, 'reconfigure'):
        sys.stdout.reconfigure(encoding='utf-8')
    print(output_json)
