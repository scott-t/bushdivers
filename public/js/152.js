"use strict";(self.webpackChunk=self.webpackChunk||[]).push([[152],{6152:(e,r,t)=>{t.r(r),t.d(r,{default:()=>m});var n=t(7294),s=t(9680),a=t(1636),o=t(795),l=t(5893);function c(e,r){var t=Object.keys(e);if(Object.getOwnPropertySymbols){var n=Object.getOwnPropertySymbols(e);r&&(n=n.filter((function(r){return Object.getOwnPropertyDescriptor(e,r).enumerable}))),t.push.apply(t,n)}return t}function i(e){for(var r=1;r<arguments.length;r++){var t=null!=arguments[r]?arguments[r]:{};r%2?c(Object(t),!0).forEach((function(r){u(e,r,t[r])})):Object.getOwnPropertyDescriptors?Object.defineProperties(e,Object.getOwnPropertyDescriptors(t)):c(Object(t)).forEach((function(r){Object.defineProperty(e,r,Object.getOwnPropertyDescriptor(t,r))}))}return e}function u(e,r,t){return r in e?Object.defineProperty(e,r,{value:t,enumerable:!0,configurable:!0,writable:!0}):e[r]=t,e}function d(e,r){return function(e){if(Array.isArray(e))return e}(e)||function(e,r){var t=null==e?null:"undefined"!=typeof Symbol&&e[Symbol.iterator]||e["@@iterator"];if(null==t)return;var n,s,a=[],o=!0,l=!1;try{for(t=t.call(e);!(o=(n=t.next()).done)&&(a.push(n.value),!r||a.length!==r);o=!0);}catch(e){l=!0,s=e}finally{try{o||null==t.return||t.return()}finally{if(l)throw s}}return a}(e,r)||function(e,r){if(!e)return;if("string"==typeof e)return p(e,r);var t=Object.prototype.toString.call(e).slice(8,-1);"Object"===t&&e.constructor&&(t=e.constructor.name);if("Map"===t||"Set"===t)return Array.from(e);if("Arguments"===t||/^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(t))return p(e,r)}(e,r)||function(){throw new TypeError("Invalid attempt to destructure non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method.")}()}function p(e,r){(null==r||r>e.length)&&(r=e.length);for(var t=0,n=new Array(r);t<r;t++)n[t]=e[t];return n}var f=function(e){var r=e.token,t=(0,a.qt)().props.errors;console.log(t);var o=d((0,n.useState)({password:"",token:r}),2),c=o[0],p=o[1];return(0,l.jsxs)("div",{className:"flex flex-col justify-center items-center",children:[(0,l.jsx)("div",{className:"mb-2 mt-8",children:(0,l.jsx)("img",{src:"https://res.cloudinary.com/dji0yvkef/image/upload/v1628691598/BDLogo.png",height:"150",width:"150"})}),(0,l.jsxs)("div",{className:"rounded-md shadow-sm bg-white p-4 w-96 m-2",children:[(0,l.jsx)("p",{className:"text-center text-2xl mb-2",children:"Reset Password"}),(0,l.jsxs)("form",{onSubmit:function(e){e.preventDefault(),s.Inertia.post("/password/reset",c)},children:[(0,l.jsxs)("div",{className:"my-2",children:[(0,l.jsx)("label",{htmlFor:"password",className:"block",children:(0,l.jsx)("span",{className:"text-gray-700",children:"Password"})}),(0,l.jsx)("input",{id:"password",value:c.password,onChange:function(e){var r=e.target.id,t=e.target.value;p((function(e){return i(i({},e),{},u({},r,t))}))},type:"password",className:"form-input form"}),t.password&&(0,l.jsx)("div",{className:"text-sm text-red-500",children:t.password})]}),(0,l.jsx)("button",{className:"btn btn-primary w-full",children:"Reset password"})]})]})]})};f.layout=function(e){return(0,l.jsx)(o.Z,{children:e,title:"Reset Password"})};const m=f},795:(e,r,t)=>{t.d(r,{Z:()=>a});t(7294);var n=t(1636),s=t(5893);function a(e){var r=e.children,t=e.title,a=(0,n.qt)().props.flash;return(0,s.jsxs)("div",{className:"h-screen",style:{backgroundImage:'url("https://res.cloudinary.com/dji0yvkef/image/upload/v1629364231/BDVA/bg-1_llda6s.jpg")'},children:[(0,s.jsx)(n.Fb,{title:t}),a.error&&(0,s.jsx)("p",{className:"text-red-500",children:a.error}),a.success&&(0,s.jsx)("p",{className:"text-green-500",children:a.success}),r]})}}}]);