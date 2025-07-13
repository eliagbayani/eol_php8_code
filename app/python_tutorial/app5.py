# -------------------------------------------------- Raising Exceptions
from timeit import timeit
print("-=" * 30)
# def calculate_xfactor(age):
#     if age <= 0:
#         raise ValueError("Age cannot be 0 or less!")
#     return 10/age
# try:
#     calculate_xfactor(-1)
# except ValueError as er:
#     print(er)
#     print(type(er))
#     print("eli is here...")

# -------------------------------------------------- Cost of Raising Exceptions
print("-=" * 30)
"""
rule of thumb: u can raise your own exceptions but only if u have to.
Let us have a test and compare code with and without raising exceptions
"""
# with this module (timeit) we can calculate the execution time of python code

code1 = """
def calculate_xfactor(age):
    if age <= 0:
        raise ValueError("Age cannot be 0 or less!")
    return 10/age
try:
    calculate_xfactor(-1)
except ValueError as er:
    pass # a pass statement doesn't do anything. We just can't have an empty except block
"""
code2 = """
def calculate_xfactor(age):
    if age <= 0:
        return None
    return 10/age

ret = calculate_xfactor(-1)
if ret == None:
    pass
"""

print("first code: ", timeit(code1, number=10000))
print("2nd code: ", timeit(code2, number=10000))
