"""Empacota arquivos versionados e documentação, omitindo configuração local."""
from pathlib import Path
import subprocess
import zipfile
import hashlib
import json

ROOT=Path(__file__).resolve().parents[1]
REPO=ROOT.parents[1]
files=subprocess.check_output(['git','ls-files','-z'],cwd=REPO).decode('utf-8').split('\0')
code=[]
for name in files:
    if not name:continue
    p=Path(name)
    if p.parts[0] in ('.vscode','.git','docs'):continue
    if p.name.startswith('.env') or p.name.endswith('.local.php'):continue
    if p.suffix in ('.log','.zip','.docx','.doc','.pdf'):continue
    if (REPO/p).is_file():code.append(p)
allowed={'.doc','.docx','.pdf','.md','.mmd','.svg','.png','.json','.py','.php','.ps1'}
docs=[p for p in ROOT.rglob('*') if p.is_file() and p.suffix in allowed and not p.name.startswith(('~$','conferencia_')) and '__pycache__' not in p.parts and p.name!='manifesto.json']
manifest={'arquivos_codigo':len(code),'arquivos_documentacao':len(docs),'conteudo':[]}
archive=ROOT/'AGENDEI - ENTREGA REVISADA.zip'
with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED,compresslevel=6) as z:
    for path in code:
        name='Agendei/'+path.as_posix()
        z.write(REPO/path,name)
        manifest['conteudo'].append({'arquivo':name,'sha256':hashlib.sha256((REPO/path).read_bytes()).hexdigest()})
    for path in docs:
        name='Documentacao/'+path.relative_to(ROOT).as_posix()
        z.write(path,name)
        manifest['conteudo'].append({'arquivo':name,'sha256':hashlib.sha256(path.read_bytes()).hexdigest()})
    z.writestr('LEIA_PRIMEIRO.txt','Entrega Agendei: Documentacao contém Word/PDF, MER/DER e registros do grupo. Agendei contém o código e os dois scripts SQL. Abra Documentacao/LEIA_PRIMEIRO.md para orientação e pontos a conferir. O número do grupo permanece XX.\n')
    z.writestr('manifesto.json',json.dumps(manifest,ensure_ascii=False,indent=2))
assert 'Agendei/banco.sql' in [x['arquivo'] for x in manifest['conteudo']]
assert 'Agendei/banco_postgres.sql' in [x['arquivo'] for x in manifest['conteudo']]
assert not any(x['arquivo'].endswith('.local.php') for x in manifest['conteudo'])
with zipfile.ZipFile(archive) as z:assert z.testzip() is None
(ROOT/'fontes/manifesto.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2),encoding='utf-8')
print('ZIP validado:',archive.name,'| código:',len(code),'| documentação:',len(docs),'| MB:',round(archive.stat().st_size/1024**2,2))
