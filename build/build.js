// Copies site/ to dist/ and minifies the HTML pages. Run: npm run build
const {minify}=require('html-minifier-terser');const fs=require('fs');const path=require('path');
const NOTE='© 2026 Conrad & Claude · HostFactor (hostfactor.co.uk). HostTally is proprietary software. All rights reserved. Copying, modifying, redistributing or reusing this code without written permission is not permitted.';
const SRC=path.join(__dirname,'..','site'),OUT=path.join(__dirname,'..','dist');
const MIN=['app/app.html','home.html','help.html','terms.html','privacy.html'];
fs.rmSync(OUT,{recursive:true,force:true});fs.cpSync(SRC,OUT,{recursive:true});
for(const f of fs.readdirSync(path.join(OUT,'app'))) if(/^README/.test(f)) fs.rmSync(path.join(OUT,'app',f));
(async()=>{for(const f of MIN){let s=fs.readFileSync(path.join(SRC,f),'utf8');
  s=s.replace(/^<!doctype html>/i,'<!doctype html>\n<!--! '+NOTE+' -->');
  const out=await minify(s,{collapseWhitespace:true,conservativeCollapse:true,removeComments:true,ignoreCustomComments:[/^!/],
    minifyCSS:true,minifyJS:{compress:{passes:2},mangle:true,format:{comments:false}},keepClosingSlash:false,removeAttributeQuotes:false,processScripts:['application/ld+json']});
  fs.writeFileSync(path.join(OUT,f),out);console.log(f,s.length,'->',out.length);}
  if(fs.existsSync(path.join(OUT,'app','config.php'))) throw new Error('config.php must not be in the repo');
})().catch(e=>{console.error(e);process.exit(1)});
