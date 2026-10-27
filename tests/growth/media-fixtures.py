"""生成无客户数据的课程资源测试样例；文件是测试生成物，写入证据目录。"""
from pathlib import Path
from docx import Document
from pptx import Presentation
from pptx.util import Inches
from PIL import Image, ImageDraw, ImageFont
out=Path(__file__).resolve().parents[3]/'任务管理/进行中/美业大学成长管理/证据/媒体样例'
out.mkdir(parents=True,exist_ok=True)
doc=Document();doc.add_heading('服务准备标准',0)
for title,body in [('一、核对需求','询问需求，核对禁忌，复述确认。'),('二、清洁双手','服务前清洁双手，确认用品准备齐全。')]:doc.add_heading(title,1);doc.add_paragraph(body)
doc.save(out/'服务准备.docx')
ppt=Presentation()
for title,body in [('服务准备','清洁双手 → 核对需求 → 准备用品'),('沟通确认','倾听需求，核对禁忌，复述确认。')]:
 slide=ppt.slides.add_slide(ppt.slide_layouts[1]);slide.shapes.title.text=title;slide.placeholders[1].text=body
ppt.save(out/'服务准备.pptx')
img=Image.new('RGB',(750,2400),'#f5f6ee');draw=ImageDraw.Draw(img)
font_path='/System/Library/Fonts/STHeiti Medium.ttc';font=ImageFont.truetype(font_path,32);small=ImageFont.truetype(font_path,25)
draw.text((55,90),'服务准备 · 长图文',fill='#1b5b4e',font=font)
for i,text in enumerate(['清洁双手','核对需求','确认禁忌','准备用品','复述确认','开始服务']):
 y=290+i*320;draw.rounded_rectangle((45,y,705,y+240),20,fill='white');draw.text((75,y+45),str(i+1)+'  '+text,fill='#1b5b4e',font=font);draw.text((75,y+115),'认真做好每一步，让专业看得见。',fill='#708165',font=small)
img.save(out/'服务准备长图.png')
# 异常样例必须是真实可识别的PDF结构，不能仅改扩展名模拟加密或超页。
from pypdf import PdfWriter
for name,count,password in [('可读样例.pdf',2,None),('超页样例.pdf',501,None),('加密样例.pdf',1,'growth-local-only')]:
 writer=PdfWriter()
 for _ in range(count):writer.add_blank_page(width=600,height=800)
 if password:writer.encrypt(password)
 with (out/name).open('wb') as file:writer.write(file)
# 格式转换只在专属测试容器临时目录执行，证据目录接收派生文件。
import subprocess
def docker(*args):subprocess.run(['docker',*args],check=True)
docker('exec','mohe-growth-local-app','mkdir','-p','/tmp/growth-legacy')
for modern,legacy in [('docx','doc'),('pptx','ppt')]:
 docker('cp',str(out/('服务准备.'+modern)),'mohe-growth-local-app:/tmp/growth-legacy/source.'+modern)
 docker('exec','mohe-growth-local-app','soffice','-env:UserInstallation=file:///tmp/growth-legacy-profile','--headless','--convert-to',legacy,'--outdir','/tmp/growth-legacy','/tmp/growth-legacy/source.'+modern)
 docker('cp','mohe-growth-local-app:/tmp/growth-legacy/source.'+legacy,str(out/('服务准备.'+legacy)))
docker('exec','mohe-growth-local-app','ffmpeg','-y','-f','lavfi','-i','color=c=0x1b5b4e:s=640x360:d=8','-c:v','libx264','-pix_fmt','yuv420p','/tmp/growth-legacy/video.mp4')
docker('cp','mohe-growth-local-app:/tmp/growth-legacy/video.mp4',str(out/'服务准备.mp4'))
print(out)
