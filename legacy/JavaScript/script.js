document.addEventListener('DOMContentLoaded', function() {
  document.getElementById('backButton').addEventListener('click', function() {
      window.history.back();
  });
});
//用于返回上一页，有时会因为DOM没加载完成就加载事件监听器导致bug，将其放入一个函数中，等待DOM加载完成后再进行监听，但是其余代码没有这种问题

const signUpButton=document.getElementById('signUp')
// 事件监听器（id=signUp）
const signInButton=document.getElementById('signIn')
// 事件监听器（id=signIn）
const container=document.getElementById('container')
// 获取元素（id=container）

signUpButton.addEventListener('click',()=>{
  container.classList.add("right-panel-active")
})
// 侦测到按下signUp、给container赋予right-panel-active

signInButton.addEventListener('click',()=>{
  container.classList.remove("right-panel-active")
})
// 侦测到按下signIn，删除container上的right-panel-active

