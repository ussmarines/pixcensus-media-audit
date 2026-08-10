#!/usr/bin/env python3
from __future__ import annotations
import argparse,json,os,shutil,subprocess,sys
from datetime import datetime,timezone
from pathlib import Path
def run(name,cmd,results,cwd,output=None):
 h=output.open('wb') if output else None
 try:code=subprocess.run(cmd,cwd=cwd,stdout=h or subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=False).returncode
 except OSError:code=127
 finally:
  if h:h.close()
 results[name]={'exit_code':code,'status':'passed' if code==0 else 'findings-or-error'};print(f"[{name}] {'OK' if code==0 else 'inspect sanitized report'}")
def main():
 p=argparse.ArgumentParser();p.add_argument('--profile',choices=('quick','full'),default='full');p.add_argument('--enforce',action='store_true');a=p.parse_args();repo=Path(__file__).resolve().parents[2];mp=Path(os.environ['LOCALAPPDATA'])/'ussmarines-security-tools'/'installed-tools.json'
 if not mp.is_file():raise RuntimeError('Run the canonical installer from SpaceShooter-2D-web or MailPerch first.')
 t=json.loads(mp.read_text(encoding='utf-8-sig'))['tools'];reports=repo/'tools'/'security'/'.reports'/datetime.now(timezone.utc).strftime('%Y%m%d-%H%M%S');reports.mkdir(parents=True);r={};guard=[sys.executable,str(repo/'.github/scripts/security_guard.py'),'--report',str(reports/'identity-guard.json')]
 if a.profile=='full':guard.append('--history')
 run('identity-guard',guard,r,repo);g=[t['gitleaks']['executable'],'git' if a.profile=='full' else 'dir','.'];
 if a.profile=='full':g+=['--log-opts=--all']
 g+=['--redact=100','--exit-code=2','--report-format=json',f"--report-path={reports/'gitleaks.json'}"];run('gitleaks',g,r,repo);run('opengrep',[t['opengrep']['executable'],'scan','--config',str(repo/'.security/opengrep/project-security.yml'),'--json-output',str(reports/'opengrep.json'),'--error','--exclude','node_modules','--exclude','vendor','--exclude','dist',str(repo)],r,repo);tv=t['trivy']['executable'];run('trivy',[tv,'filesystem','--scanners','vuln,misconfig','--include-dev-deps','--severity','MEDIUM,HIGH,CRITICAL','--format','json','--output',str(reports/'trivy.json'),'--exit-code','1',str(repo)],r,repo);run('sbom',[tv,'filesystem','--scanners','vuln','--include-dev-deps','--format','cyclonedx','--output',str(reports/'sbom.cdx.json'),str(repo)],r,repo)
 if (repo/'package-lock.json').is_file() and shutil.which('npm.cmd'):run('npm-audit',['npm.cmd','audit','--omit=dev','--audit-level=moderate','--json'],r,repo,reports/'npm-audit.json')
 if (repo/'composer.lock').is_file():
  c=shutil.which('composer.bat') or shutil.which('composer')
  if c:run('composer-audit',[c,'audit','--locked','--format=json'],r,repo,reports/'composer-audit.json')
 run('zizmor',[t['zizmor']['executable'],'--offline','--format','json',str(repo)],r,repo,reports/'zizmor.json');failed=sum(x['exit_code']!=0 for x in r.values());(reports/'summary.json').write_text(json.dumps({'schema_version':1,'generated_at_utc':datetime.now(timezone.utc).isoformat(),'profile':a.profile,'safe_output':True,'matched_values_included':False,'failed_checks':failed,'results':r},indent=2,ensure_ascii=False)+'\n',encoding='utf-8');print(f'Sanitized reports: {reports}');return 1 if a.enforce and failed else 0
if __name__=='__main__':raise SystemExit(main())
