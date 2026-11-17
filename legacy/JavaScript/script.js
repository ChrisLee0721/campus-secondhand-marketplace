document.addEventListener('DOMContentLoaded', function() {
  document.getElementById('backButton').addEventListener('click', function() {
      window.history.back();
  });
});
// Goes back to the previous page; attaching the listener before the DOM has loaded can cause bugs, so it is wrapped in a function and only attached once the DOM is ready. The rest of the code does not have this problem

const signUpButton=document.getElementById('signUp')
// Event listener (id=signUp)
const signInButton=document.getElementById('signIn')
// Event listener (id=signIn)
const container=document.getElementById('container')
// Get the element (id=container)

signUpButton.addEventListener('click',()=>{
  container.classList.add("right-panel-active")
})
// On signUp click, add right-panel-active to container

signInButton.addEventListener('click',()=>{
  container.classList.remove("right-panel-active")
})
// On signIn click, remove right-panel-active from container

